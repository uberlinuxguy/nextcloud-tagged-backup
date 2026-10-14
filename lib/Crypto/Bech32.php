<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Crypto;

/**
 * Minimal BIP-173 Bech32 decoder, as used for age recipient strings.
 */
class Bech32 {
	private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	private const GENERATORS = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

	/**
	 * @return array{0: string, 1: string} [human readable part, decoded bytes]
	 */
	public static function decode(string $encoded): array {
		if (strtolower($encoded) !== $encoded && strtoupper($encoded) !== $encoded) {
			throw new \InvalidArgumentException('Mixed case Bech32 string');
		}
		$encoded = strtolower($encoded);
		$separator = strrpos($encoded, '1');
		if ($separator === false || $separator < 1 || $separator + 7 > strlen($encoded)) {
			throw new \InvalidArgumentException('Invalid Bech32 string');
		}
		$hrp = substr($encoded, 0, $separator);
		$values = [];
		for ($i = $separator + 1, $len = strlen($encoded); $i < $len; $i++) {
			$pos = strpos(self::CHARSET, $encoded[$i]);
			if ($pos === false) {
				throw new \InvalidArgumentException('Invalid Bech32 character');
			}
			$values[] = $pos;
		}
		if (self::polymod(array_merge(self::expandHrp($hrp), $values)) !== 1) {
			throw new \InvalidArgumentException('Invalid Bech32 checksum');
		}
		$data = self::convertBits(array_slice($values, 0, -6), 5, 8);
		return [$hrp, implode('', array_map('chr', $data))];
	}

	/**
	 * @param int[] $values
	 */
	private static function polymod(array $values): int {
		$chk = 1;
		foreach ($values as $value) {
			$top = $chk >> 25;
			$chk = (($chk & 0x1ffffff) << 5) ^ $value;
			for ($i = 0; $i < 5; $i++) {
				if (($top >> $i) & 1) {
					$chk ^= self::GENERATORS[$i];
				}
			}
		}
		return $chk;
	}

	/**
	 * @return int[]
	 */
	private static function expandHrp(string $hrp): array {
		$high = [];
		$low = [];
		for ($i = 0, $len = strlen($hrp); $i < $len; $i++) {
			$high[] = ord($hrp[$i]) >> 5;
			$low[] = ord($hrp[$i]) & 31;
		}
		return array_merge($high, [0], $low);
	}

	/**
	 * @param int[] $data
	 * @return int[]
	 */
	private static function convertBits(array $data, int $from, int $to): array {
		$acc = 0;
		$bits = 0;
		$result = [];
		$maxv = (1 << $to) - 1;
		foreach ($data as $value) {
			$acc = ($acc << $from) | $value;
			$bits += $from;
			while ($bits >= $to) {
				$bits -= $to;
				$result[] = ($acc >> $bits) & $maxv;
			}
		}
		if ($bits >= $from || (($acc << ($to - $bits)) & $maxv) !== 0) {
			throw new \InvalidArgumentException('Invalid Bech32 padding');
		}
		return $result;
	}
}
