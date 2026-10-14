<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

class GzipSink implements Sink {
	private \DeflateContext $context;
	private bool $closed = false;

	public function __construct(
		private Sink $next,
		int $level = 6,
	) {
		$context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $level]);
		if ($context === false) {
			throw new \RuntimeException('Could not initialise gzip compression');
		}
		$this->context = $context;
	}

	public function write(string $data): void {
		$out = deflate_add($this->context, $data, ZLIB_NO_FLUSH);
		if ($out === false) {
			throw new \RuntimeException('gzip compression failed');
		}
		if ($out !== '') {
			$this->next->write($out);
		}
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		$out = deflate_add($this->context, '', ZLIB_FINISH);
		if ($out === false) {
			throw new \RuntimeException('gzip compression failed');
		}
		if ($out !== '') {
			$this->next->write($out);
		}
		$this->next->close();
	}
}
