<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Service;

use OCA\TaggedBackup\Db\Job;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Resolves the tags of a backup job to the set of files and folders to back up.
 */
class FileCollector {
	public function __construct(
		private ISystemTagManager $tagManager,
		private ISystemTagObjectMapper $tagObjectMapper,
		private IRootFolder $rootFolder,
		private IUserMountCache $userMountCache,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{entries: BackupEntry[], tags: array<string, string>, missing: array<array{fileId: string, reason: string}>, unreachable: string[]}
	 */
	public function collect(Job $job): array {
		$tagIds = $job->getTagIdList();
		$tagNames = [];
		foreach ($this->tagManager->getTagsByIds($tagIds) as $tag) {
			$tagNames[$tag->getId()] = $tag->getName();
		}

		$objectIds = $this->tagObjectMapper->getObjectIdsForTags($tagIds, 'files');
		$tagsByObject = $objectIds === [] ? [] : $this->tagObjectMapper->getTagIdsForObjects($objectIds, 'files');

		$roots = [];
		$missing = [];
		$unreachable = [];
		foreach ($objectIds as $objectId) {
			$resolved = $this->resolve((int)$objectId, $job->getScopeUser());
			if ($resolved === null) {
				// Deleted, in the trash bin, or outside the job's user scope.
				// Tag assignments of deleted files can linger, so this is not
				// treated as an error.
				$unreachable[] = (string)$objectId;
				continue;
			}
			$tags = array_values(array_filter(array_map(
				fn ($id) => $tagNames[$id] ?? null,
				$tagsByObject[$objectId] ?? [],
			)));
			$roots[] = [$resolved[0], $resolved[1], $tags];
		}

		// Process shallow paths first so nested tagged items are de-duplicated
		// against their already-included ancestors.
		usort($roots, fn ($a, $b) => strcmp($a[0]->getPath(), $b[0]->getPath()));

		$entries = [];
		$seenIds = [];
		$seenPaths = [];
		foreach ($roots as [$node, $user, $tags]) {
			$this->walk($node, $user, $tags, $job->getScopeUser() !== null, $entries, $seenIds, $seenPaths, $missing);
		}

		return ['entries' => $entries, 'tags' => $tagNames, 'missing' => $missing, 'unreachable' => $unreachable];
	}

	/**
	 * @return array{0: Node, 1: string}|null node and the user whose view it is in
	 */
	private function resolve(int $fileId, ?string $scopeUser): ?array {
		if ($scopeUser !== null) {
			$node = $this->rootFolder->getUserFolder($scopeUser)->getFirstNodeById($fileId);
			return $node === null ? null : [$node, $scopeUser];
		}

		// Pick the user who can see the file. Prefer the owner's home storage
		// (mount point "/<uid>/") over shares, group folders, etc.
		$mounts = $this->userMountCache->getMountsForFileId($fileId);
		usort($mounts, function ($a, $b) {
			$aHome = substr_count(trim($a->getMountPoint(), '/'), '/') === 0;
			$bHome = substr_count(trim($b->getMountPoint(), '/'), '/') === 0;
			return [$bHome, $a->getUser()->getUID()] <=> [$aHome, $b->getUser()->getUID()];
		});
		foreach ($mounts as $mount) {
			$uid = $mount->getUser()->getUID();
			try {
				$node = $this->rootFolder->getUserFolder($uid)->getFirstNodeById($fileId);
			} catch (\Throwable $e) {
				$this->logger->warning('Tagged backup: cannot access files of user {user}: {error}', [
					'user' => $uid, 'error' => $e->getMessage(), 'app' => 'tagged_backup',
				]);
				continue;
			}
			if ($node !== null) {
				return [$node, $uid];
			}
		}
		return null;
	}

	/**
	 * @param string[] $tags
	 * @param BackupEntry[] $entries
	 * @param array<int, true> $seenIds
	 * @param array<string, true> $seenPaths
	 * @param array<array{fileId: string, reason: string}> $missing
	 */
	private function walk(Node $node, string $user, array $tags, bool $scoped, array &$entries, array &$seenIds, array &$seenPaths, array &$missing): void {
		$fileId = $node->getId();
		$userFolder = $this->rootFolder->getUserFolder($user);
		$relative = ltrim((string)$userFolder->getRelativePath($node->getPath()), '/');
		if ($relative === '') {
			return; // never back up a whole user's root folder by accident
		}
		$archivePath = 'files/' . ($scoped ? '' : $user . '/') . $relative;
		if (($fileId !== null && isset($seenIds[$fileId])) || isset($seenPaths[$archivePath])) {
			return;
		}
		if ($fileId !== null) {
			$seenIds[$fileId] = true;
		}
		$seenPaths[$archivePath] = true;

		$isDirectory = $node instanceof Folder;
		$entries[] = new BackupEntry($node, $archivePath, $user, '/' . $relative, $tags, $isDirectory);

		if ($isDirectory) {
			try {
				$children = $node->getDirectoryListing();
			} catch (\Throwable $e) {
				$this->logger->warning('Tagged backup: cannot list {path}: {error}', [
					'path' => $node->getPath(), 'error' => $e->getMessage(), 'app' => 'tagged_backup',
				]);
				$missing[] = ['fileId' => (string)$fileId, 'reason' => 'cannot list folder ' . $archivePath . ': ' . $e->getMessage()];
				return;
			}
			usort($children, fn (Node $a, Node $b) => strcmp($a->getName(), $b->getName()));
			foreach ($children as $child) {
				$this->walk($child, $user, $tags, $scoped, $entries, $seenIds, $seenPaths, $missing);
			}
		}
	}
}
