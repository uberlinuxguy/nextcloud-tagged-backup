<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

/**
 * A byte sink. Sinks are chained: tar -> compression -> encryption -> file.
 */
interface Sink {
	public function write(string $data): void;

	/**
	 * Flush any buffered data and close this sink and every sink after it.
	 */
	public function close(): void;
}
