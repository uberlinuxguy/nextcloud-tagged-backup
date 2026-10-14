<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Service;

use OCP\Files\Node;

class BackupEntry {
	/**
	 * @param string[] $tags names of the tags that caused this entry to be included
	 */
	public function __construct(
		public readonly Node $node,
		public readonly string $archivePath,
		public readonly string $user,
		public readonly string $nextcloudPath,
		public readonly array $tags,
		public readonly bool $isDirectory,
	) {
	}
}
