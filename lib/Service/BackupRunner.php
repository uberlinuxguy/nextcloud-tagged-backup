<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Service;

use OCA\TaggedBackup\AppInfo\Application;
use OCA\TaggedBackup\Archive\Bzip2Sink;
use OCA\TaggedBackup\Archive\FileSink;
use OCA\TaggedBackup\Archive\GzipSink;
use OCA\TaggedBackup\Archive\Sink;
use OCA\TaggedBackup\Archive\TarWriter;
use OCA\TaggedBackup\Crypto\AgeEncryptSink;
use OCA\TaggedBackup\Crypto\AgeRecipients;
use OCA\TaggedBackup\Db\Job;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Destination\Destination;
use OCA\TaggedBackup\Destination\DestinationFactory;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class BackupRunner {
	public const MANIFEST_VERSION = 1;
	private const READ_SIZE = 1048576;
	/** A run holding the lock longer than this is assumed to have crashed. */
	private const STALE_LOCK_SECONDS = 86400;

	public function __construct(
		private JobMapper $mapper,
		private FileCollector $collector,
		private DestinationFactory $destinationFactory,
		private ITempManager $tempManager,
		private IAppConfig $appConfig,
		private IConfig $config,
		private IAppManager $appManager,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Run a backup job: collect, archive, upload, prune.
	 *
	 * @param callable(string): void|null $progress receives human readable progress lines
	 * @return array{status: string, message: string, name?: string}
	 */
	public function run(Job $job, ?callable $progress = null): array {
		$progress ??= static function (string $line): void {
		};
		$now = $this->timeFactory->getTime();
		if (!$this->mapper->tryLock($job->getId(), $now, $now - self::STALE_LOCK_SECONDS)) {
			return ['status' => 'skipped', 'message' => 'Another run of this job is in progress'];
		}

		@set_time_limit(0);
		$tempFile = null;
		$result = ['status' => Job::STATUS_FAILED, 'message' => ''];
		$size = null;
		$fileCount = null;
		try {
			// Fail early on a bad configuration, before doing expensive work.
			$destination = $this->destinationFactory->create($job);
			$recipients = $job->isEncrypted() ? array_values(AgeRecipients::parse((string)$job->getAgeRecipients())) : [];

			$progress('Collecting tagged files…');
			$collected = $this->collector->collect($job);
			$entries = $collected['entries'];
			$progress(sprintf('Found %d files and folders', count($entries)));

			$tempFile = $this->createTempFile();
			$name = $this->backupName($job, $now);
			$handle = fopen($tempFile, 'wb');
			if ($handle === false) {
				throw new \RuntimeException('Could not create temporary file ' . $tempFile);
			}
			$fileSink = new FileSink($handle);
			$tar = new TarWriter($this->buildSinkChain($job, $fileSink, $recipients));

			$manifestEntries = [];
			$problems = $collected['missing'];
			$totalBytes = 0;
			foreach ($entries as $i => $entry) {
				$manifestEntry = $this->addEntry($tar, $entry, $problems);
				$manifestEntries[] = $manifestEntry;
				$totalBytes += $manifestEntry['size'] ?? 0;
				if (($i + 1) % 500 === 0) {
					$progress(sprintf('Archived %d/%d entries', $i + 1, count($entries)));
				}
			}

			$manifest = $this->buildManifest($job, $now, $name, $collected['tags'], $manifestEntries, $problems, $collected['unreachable'], $totalBytes, $recipients !== []);
			$tar->addFileFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", $now);
			$tar->finish();
			$size = $fileSink->getBytesWritten();
			$fileCount = count(array_filter($manifestEntries, fn ($e) => $e['type'] === 'file'));

			$progress(sprintf('Uploading %s (%s)…', $name, self::formatBytes($size)));
			$destination->upload($tempFile, $name, $size);

			$pruned = $this->applyRetention($job, $destination, $progress);

			$result = [
				'status' => $problems === [] ? Job::STATUS_SUCCESS : Job::STATUS_PARTIAL,
				'message' => sprintf('Uploaded %s: %d files, %s of data, %s archive', $name, $fileCount, self::formatBytes($totalBytes), self::formatBytes($size))
					. ($problems === [] ? '' : sprintf('; %d item(s) could not be backed up (see manifest)', count($problems)))
					. ($pruned > 0 ? sprintf('; removed %d old backup(s)', $pruned) : ''),
				'name' => $name,
			];
		} catch (\Throwable $e) {
			$this->logger->error('Tagged backup job "{name}" failed: {error}', [
				'name' => $job->getName(), 'error' => $e->getMessage(), 'exception' => $e, 'app' => Application::APP_ID,
			]);
			$result = ['status' => Job::STATUS_FAILED, 'message' => $e->getMessage()];
		} finally {
			if ($tempFile !== null && file_exists($tempFile)) {
				unlink($tempFile);
			}
			$job->setLastRunAt($now);
			$job->setLastStatus($result['status']);
			$job->setLastMessage(mb_substr($result['message'], 0, 4000));
			$job->setLastSize($result['status'] === Job::STATUS_FAILED ? null : $size);
			$job->setLastFileCount($result['status'] === Job::STATUS_FAILED ? null : $fileCount);
			try {
				$this->mapper->update($job);
			} finally {
				$this->mapper->unlock($job->getId());
			}
		}
		$progress($result['message']);
		return $result;
	}

	/**
	 * @param string[] $recipients
	 */
	private function buildSinkChain(Job $job, Sink $sink, array $recipients): Sink {
		if ($recipients !== []) {
			$sink = new AgeEncryptSink($sink, $recipients);
		}
		return match ($job->getCompression()) {
			'gzip' => new GzipSink($sink),
			'bzip2' => new Bzip2Sink($sink),
			default => $sink,
		};
	}

	/**
	 * @param array<array{fileId: string, reason: string}> $problems
	 * @return array<string, mixed> manifest entry
	 */
	private function addEntry(TarWriter $tar, BackupEntry $entry, array &$problems): array {
		$node = $entry->node;
		$mtime = (int)$node->getMTime();
		$manifestEntry = [
			'path' => $entry->archivePath,
			'type' => $entry->isDirectory ? 'directory' : 'file',
			'user' => $entry->user,
			'nextcloudPath' => $entry->nextcloudPath,
			'fileId' => $node->getId(),
			'mtime' => $mtime,
			'tags' => $entry->tags,
		];
		if ($entry->isDirectory) {
			$tar->addDirectory($entry->archivePath, $mtime);
			return $manifestEntry;
		}
		/** @var File $node */
		$manifestEntry['mimetype'] = $node->getMimetype();
		$manifestEntry['etag'] = $node->getEtag();

		$declaredSize = (int)$node->getSize();
		try {
			$stream = $node->fopen('rb');
			if ($stream === false) {
				throw new \RuntimeException('could not open file');
			}
		} catch (\Throwable $e) {
			$problems[] = ['fileId' => (string)$node->getId(), 'path' => $entry->archivePath, 'reason' => 'not readable: ' . $e->getMessage()];
			$manifestEntry['error'] = 'not readable: ' . $e->getMessage();
			$manifestEntry['size'] = 0;
			$manifestEntry['included'] = false;
			return $manifestEntry;
		}

		// The tar header needs the size up front. If the file turns out to be
		// shorter or longer than its metadata says (changed while reading, or
		// storage errors), pad/truncate so the archive stays valid and record
		// the problem.
		$tar->beginFile($entry->archivePath, $declaredSize, $mtime);
		$hash = hash_init('sha256');
		$written = 0;
		$error = null;
		try {
			while ($written < $declaredSize && !feof($stream)) {
				$data = fread($stream, min(self::READ_SIZE, $declaredSize - $written));
				if ($data === false) {
					throw new \RuntimeException('read error');
				}
				if ($data === '') {
					continue;
				}
				hash_update($hash, $data);
				$tar->writeData($data);
				$written += strlen($data);
			}
			if ($written === $declaredSize && fread($stream, 1) !== '') {
				$error = 'file grew while being read; truncated to ' . $declaredSize . ' bytes';
			}
		} catch (\Throwable $e) {
			$error = 'read failed after ' . $written . ' bytes: ' . $e->getMessage();
		} finally {
			fclose($stream);
		}
		if ($written < $declaredSize) {
			$error ??= 'file shrank while being read; only ' . $written . ' of ' . $declaredSize . ' bytes available';
			$padding = $declaredSize - $written;
			while ($padding > 0) {
				$chunk = min($padding, self::READ_SIZE);
				$tar->writeData(str_repeat("\0", $chunk));
				$padding -= $chunk;
			}
		}
		$tar->endFile($declaredSize);

		$manifestEntry['size'] = $declaredSize;
		if ($error === null) {
			$manifestEntry['sha256'] = hash_final($hash);
		} else {
			$manifestEntry['error'] = $error;
			$problems[] = ['fileId' => (string)$node->getId(), 'path' => $entry->archivePath, 'reason' => $error];
		}
		return $manifestEntry;
	}

	/**
	 * @param array<string, string> $tags
	 * @param array<array<string, mixed>> $entries
	 * @param array<array<string, string>> $problems
	 * @param string[] $unreachable
	 * @return array<string, mixed>
	 */
	private function buildManifest(Job $job, int $now, string $name, array $tags, array $entries, array $problems, array $unreachable, int $totalBytes, bool $encrypted): array {
		return [
			'format' => 'nextcloud-tagged-backup',
			'formatVersion' => self::MANIFEST_VERSION,
			'generator' => 'tagged_backup ' . $this->appManager->getAppVersion(Application::APP_ID),
			'instance' => [
				'id' => $this->config->getSystemValueString('instanceid'),
				'url' => $this->config->getSystemValueString('overwrite.cli.url'),
			],
			'createdAt' => gmdate('c', $now),
			'job' => [
				'id' => $job->getId(),
				'name' => $job->getName(),
				'scopeUser' => $job->getScopeUser(),
				'tags' => array_map(fn ($id, $name) => ['id' => (string)$id, 'name' => $name], array_keys($tags), $tags),
			],
			'archive' => [
				'name' => $name,
				'compression' => $job->getCompression(),
				'encryption' => $encrypted ? 'age-x25519' : 'none',
			],
			'totals' => [
				'files' => count(array_filter($entries, fn ($e) => $e['type'] === 'file')),
				'directories' => count(array_filter($entries, fn ($e) => $e['type'] === 'directory')),
				'bytes' => $totalBytes,
				'problems' => count($problems),
			],
			'problems' => $problems,
			// File IDs carrying a selected tag that are not in any user's files
			// (deleted, trashed, or outside the job's user scope).
			'unreachableFileIds' => $unreachable,
			'entries' => $entries,
		];
	}

	private function backupName(Job $job, int $now): string {
		$name = $job->getFilePrefix() . '-' . gmdate('Ymd-His', $now) . 'Z.tar';
		$name .= match ($job->getCompression()) {
			'gzip' => '.gz',
			'bzip2' => '.bz2',
			default => '',
		};
		return $job->isEncrypted() ? $name . '.age' : $name;
	}

	/**
	 * Delete the oldest backups of this job beyond the retention count.
	 */
	private function applyRetention(Job $job, Destination $destination, callable $progress): int {
		$keep = $job->getRetentionCount();
		if ($keep <= 0) {
			return 0;
		}
		$pattern = '/^' . preg_quote($job->getFilePrefix(), '/') . '-\d{8}-\d{6}Z\.tar(\.gz|\.bz2)?(\.age)?$/';
		$backups = array_filter($destination->listFiles(), fn ($f) => preg_match($pattern, $f->name) === 1);
		// Names embed a sortable UTC timestamp.
		usort($backups, fn ($a, $b) => strcmp($b->name, $a->name));
		$deleted = 0;
		foreach (array_slice($backups, $keep) as $old) {
			$progress('Removing old backup ' . $old->name);
			$destination->delete($old);
			$deleted++;
		}
		return $deleted;
	}

	private function createTempFile(): string {
		$dir = $this->appConfig->getValueString(Application::APP_ID, 'temp_directory', '');
		if ($dir === '') {
			$dir = $this->tempManager->getTempBaseDir();
		}
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new \RuntimeException('Temporary directory ' . $dir . ' is not writable');
		}
		$file = tempnam($dir, 'tagged_backup_');
		if ($file === false) {
			throw new \RuntimeException('Could not create temporary file in ' . $dir);
		}
		chmod($file, 0600);
		return $file;
	}

	public static function formatBytes(int $bytes): string {
		$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
		$i = 0;
		$value = (float)$bytes;
		while ($value >= 1024 && $i < count($units) - 1) {
			$value /= 1024;
			$i++;
		}
		return ($i === 0 ? (string)$bytes : number_format($value, 1)) . ' ' . $units[$i];
	}
}
