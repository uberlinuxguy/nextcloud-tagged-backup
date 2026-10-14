<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

class RemoteFile {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
	) {
	}
}
