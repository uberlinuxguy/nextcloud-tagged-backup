<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

/**
 * Terminal sink writing to a stream resource.
 */
class FileSink implements Sink {
	/** @var resource */
	private $handle;
	private int $bytesWritten = 0;
	private bool $closed = false;

	/**
	 * @param resource $handle
	 */
	public function __construct($handle) {
		$this->handle = $handle;
	}

	public function write(string $data): void {
		$length = strlen($data);
		$offset = 0;
		while ($offset < $length) {
			$written = fwrite($this->handle, $offset === 0 ? $data : substr($data, $offset));
			if ($written === false || $written === 0) {
				throw new \RuntimeException('Failed to write to backup file (disk full?)');
			}
			$offset += $written;
		}
		$this->bytesWritten += $length;
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		if (fflush($this->handle) === false) {
			throw new \RuntimeException('Failed to flush backup file');
		}
		fclose($this->handle);
	}

	public function getBytesWritten(): int {
		return $this->bytesWritten;
	}
}
