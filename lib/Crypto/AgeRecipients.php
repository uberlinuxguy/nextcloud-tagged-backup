<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Crypto;

class AgeRecipients {
	/**
	 * Parse a newline separated list of age X25519 recipients ("age1...").
	 * Blank lines and lines starting with '#' are ignored, matching age's
	 * recipients file format.
	 *
	 * @return array<string, string> recipient string => 32 byte public key
	 * @throws \InvalidArgumentException
	 */
	public static function parse(string $text): array {
		$result = [];
		foreach (preg_split('/\r?\n/', $text) as $lineNo => $line) {
			$line = trim($line);
			if ($line === '' || str_starts_with($line, '#')) {
				continue;
			}
			try {
				[$hrp, $key] = Bech32::decode($line);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException(sprintf('Line %d: not a valid age recipient (%s)', $lineNo + 1, $e->getMessage()));
			}
			if ($hrp !== 'age' || strlen($key) !== 32) {
				throw new \InvalidArgumentException(sprintf('Line %d: only age X25519 recipients ("age1...") are supported', $lineNo + 1));
			}
			$result[strtolower($line)] = $key;
		}
		return $result;
	}
}
