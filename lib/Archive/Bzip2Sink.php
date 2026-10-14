<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

/**
 * PHP has no incremental bzip2 API, only the bzip2.compress stream filter.
 * We open a stream on {@see SinkStreamWrapper} (which forwards everything it
 * receives to the next sink) and attach the filter to it.
 */
class Bzip2Sink implements Sink {
	/** @var resource */
	private $stream;
	private bool $closed = false;

	public function __construct(
		private Sink $next,
		int $blockSize = 9,
	) {
		if (!in_array('bzip2.*', stream_get_filters(), true)) {
			throw new \RuntimeException('bzip2 compression requires the PHP bz2 extension');
		}
		$this->stream = SinkStreamWrapper::open($next);
		$filter = stream_filter_append($this->stream, 'bzip2.compress', STREAM_FILTER_WRITE, ['blocks' => $blockSize]);
		if ($filter === false) {
			throw new \RuntimeException('Could not initialise bzip2 compression');
		}
	}

	public function write(string $data): void {
		if (fwrite($this->stream, $data) !== strlen($data)) {
			throw new \RuntimeException('bzip2 compression failed');
		}
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		// Closing the stream flushes the filter, which emits the final block.
		fclose($this->stream);
		$this->next->close();
	}
}
