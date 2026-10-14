<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

/**
 * Write-only stream wrapper that forwards writes to a {@see Sink}, so PHP
 * stream filters can be placed in front of a sink.
 */
class SinkStreamWrapper {
	private const PROTOCOL = 'taggedbackupsink';

	/** @var array<string, Sink> */
	private static array $pending = [];

	/** @var resource|null */
	public $context;
	private ?Sink $sink = null;

	/**
	 * @return resource
	 */
	public static function open(Sink $sink) {
		if (!in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
			stream_wrapper_register(self::PROTOCOL, self::class);
		}
		$id = bin2hex(random_bytes(8));
		self::$pending[$id] = $sink;
		try {
			$handle = fopen(self::PROTOCOL . '://' . $id, 'wb');
		} finally {
			unset(self::$pending[$id]);
		}
		if ($handle === false) {
			throw new \RuntimeException('Could not open sink stream');
		}
		return $handle;
	}

	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		$id = substr($path, strlen(self::PROTOCOL) + 3);
		if (!isset(self::$pending[$id])) {
			return false;
		}
		$this->sink = self::$pending[$id];
		return true;
	}

	public function stream_write(string $data): int {
		$this->sink?->write($data);
		return strlen($data);
	}

	public function stream_flush(): bool {
		return true;
	}

	public function stream_close(): void {
		$this->sink = null;
	}

	public function stream_eof(): bool {
		return true;
	}
}
