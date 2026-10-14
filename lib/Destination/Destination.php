<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

interface Destination {
	/**
	 * Verify credentials and that the target location is writable.
	 *
	 * @throws DestinationException
	 */
	public function test(): void;

	/**
	 * @throws DestinationException
	 */
	public function upload(string $localPath, string $name, int $size): void;

	/**
	 * List backup files in the target location.
	 *
	 * @return RemoteFile[]
	 * @throws DestinationException
	 */
	public function listFiles(): array;

	/**
	 * @throws DestinationException
	 */
	public function delete(RemoteFile $file): void;
}
