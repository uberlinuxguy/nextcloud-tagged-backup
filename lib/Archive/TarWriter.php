<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Archive;

/**
 * Streaming POSIX (pax) tar writer. Plain ustar headers are used where
 * possible; a pax extended header is added for long or non-ASCII paths and
 * for files of 8 GiB or more.
 */
class TarWriter {
	private const BLOCK = 512;
	private const USTAR_MAX_SIZE = 077777777777;

	private int $paxCounter = 0;

	public function __construct(
		private Sink $sink,
	) {
	}

	public function addDirectory(string $path, int $mtime): void {
		$this->writeHeader(rtrim($path, '/') . '/', 0, $mtime, 0755, '5');
	}

	/**
	 * Begin a regular file entry. The caller must then write exactly $size
	 * bytes via {@see writeData()} and call {@see endFile()}.
	 */
	public function beginFile(string $path, int $size, int $mtime): void {
		$this->writeHeader($path, $size, $mtime, 0644, '0');
	}

	public function writeData(string $data): void {
		$this->sink->write($data);
	}

	public function endFile(int $size): void {
		$this->pad($size);
	}

	public function addFileFromString(string $path, string $contents, int $mtime): void {
		$this->beginFile($path, strlen($contents), $mtime);
		$this->writeData($contents);
		$this->endFile(strlen($contents));
	}

	/**
	 * Write the end-of-archive marker and close the sink chain.
	 */
	public function finish(): void {
		$this->sink->write(str_repeat("\0", self::BLOCK * 2));
		$this->sink->close();
	}

	private function writeHeader(string $path, int $size, int $mtime, int $mode, string $type): void {
		$path = ltrim($path, '/');
		$pax = [];
		[$prefix, $name] = $this->splitUstarPath($path);
		if ($name === null) {
			$pax['path'] = $path;
			$name = $this->fallbackName($path, $type);
			$prefix = '';
		}
		if ($size > self::USTAR_MAX_SIZE) {
			$pax['size'] = (string)$size;
		}
		if ($mtime < 0 || $mtime > 077777777777) {
			$pax['mtime'] = (string)$mtime;
			$mtime = 0;
		}
		if ($pax !== []) {
			$records = '';
			foreach ($pax as $key => $value) {
				$records .= self::paxRecord($key, $value);
			}
			$this->paxCounter++;
			$this->sink->write($this->buildHeader('PaxHeaders/' . $this->paxCounter, '', strlen($records), $mtime, 0644, 'x'));
			$this->sink->write($records);
			$this->pad(strlen($records));
		}
		$this->sink->write($this->buildHeader($name, $prefix, $size > self::USTAR_MAX_SIZE ? 0 : $size, $mtime, $mode, $type));
	}

	/**
	 * @return array{0: string, 1: ?string} [prefix, name]; name is null if the
	 *                                     path cannot be represented in ustar
	 */
	private function splitUstarPath(string $path): array {
		if (!mb_check_encoding($path, 'ASCII')) {
			return ['', null];
		}
		if (strlen($path) <= 100) {
			return ['', $path];
		}
		// Split at a '/' so that prefix <= 155 and name <= 100 bytes.
		$trimmed = rtrim($path, '/');
		$offset = strlen($trimmed);
		while (($pos = strrpos(substr($trimmed, 0, $offset), '/')) !== false) {
			$prefix = substr($path, 0, $pos);
			$name = substr($path, $pos + 1);
			if (strlen($name) > 100) {
				break;
			}
			if (strlen($prefix) <= 155 && $name !== '') {
				return [$prefix, $name];
			}
			$offset = $pos;
		}
		return ['', null];
	}

	private function fallbackName(string $path, string $type): string {
		$ascii = preg_replace('/[^\x20-\x7e]/', '_', $path);
		$name = substr($ascii, -99);
		return $type === '5' ? rtrim($name, '/') . '/' : $name;
	}

	private function buildHeader(string $name, string $prefix, int $size, int $mtime, int $mode, string $type): string {
		$header = str_pad($name, 100, "\0")
			. self::octal($mode, 8)
			. self::octal(0, 8) // uid
			. self::octal(0, 8) // gid
			. self::octal($size, 12)
			. self::octal($mtime, 12)
			. str_repeat(' ', 8) // checksum placeholder
			. $type
			. str_repeat("\0", 100) // linkname
			. "ustar\0" . '00'
			. str_pad('nextcloud', 32, "\0")
			. str_pad('nextcloud', 32, "\0")
			. self::octal(0, 8) // devmajor
			. self::octal(0, 8) // devminor
			. str_pad($prefix, 155, "\0");
		$header = str_pad($header, self::BLOCK, "\0");
		$checksum = array_sum(array_map('ord', str_split($header)));
		return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
	}

	private static function octal(int $value, int $width): string {
		return sprintf('%0' . ($width - 1) . 'o', $value) . "\0";
	}

	private static function paxRecord(string $key, string $value): string {
		$body = ' ' . $key . '=' . $value . "\n";
		$length = strlen($body);
		$total = $length + strlen((string)$length);
		if (strlen((string)$total) !== strlen((string)$length)) {
			$total = $length + strlen((string)$total);
		}
		return $total . $body;
	}

	private function pad(int $size): void {
		$remainder = $size % self::BLOCK;
		if ($remainder !== 0) {
			$this->sink->write(str_repeat("\0", self::BLOCK - $remainder));
		}
	}
}
