<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Crypto;

use OCA\TaggedBackup\Archive\Sink;

/**
 * Streaming encryptor producing the age v1 format (https://age-encryption.org/v1)
 * for X25519 recipients. Output can be decrypted with the reference `age`
 * tool or any compatible implementation:
 *
 *     age --decrypt -i key.txt backup.tar.gz.age > backup.tar.gz
 */
class AgeEncryptSink implements Sink {
	private const CHUNK_SIZE = 65536;

	private string $payloadKey;
	private string $buffer = '';
	private int $counter = 0;
	private bool $closed = false;

	/**
	 * @param string[] $recipientKeys 32 byte X25519 public keys
	 */
	public function __construct(
		private Sink $next,
		array $recipientKeys,
	) {
		if ($recipientKeys === []) {
			throw new \InvalidArgumentException('At least one age recipient is required');
		}
		$fileKey = random_bytes(16);

		$header = "age-encryption.org/v1\n";
		foreach ($recipientKeys as $recipient) {
			$header .= $this->x25519Stanza($fileKey, $recipient);
		}
		$header .= '---';
		$macKey = hash_hkdf('sha256', $fileKey, 32, 'header', '');
		$header .= ' ' . self::b64(hash_hmac('sha256', $header, $macKey, true)) . "\n";

		$nonce = random_bytes(16);
		$this->payloadKey = hash_hkdf('sha256', $fileKey, 32, 'payload', $nonce);
		sodium_memzero($fileKey);

		$this->next->write($header . $nonce);
	}

	private function x25519Stanza(string $fileKey, string $recipient): string {
		$ephemeralSecret = random_bytes(SODIUM_CRYPTO_SCALARMULT_SCALARBYTES);
		$ephemeralShare = sodium_crypto_scalarmult_base($ephemeralSecret);
		$shared = sodium_crypto_scalarmult($ephemeralSecret, $recipient);
		sodium_memzero($ephemeralSecret);
		if ($shared === str_repeat("\0", 32)) {
			throw new \InvalidArgumentException('Invalid age recipient (low order point)');
		}
		$wrapKey = hash_hkdf('sha256', $shared, 32, 'age-encryption.org/v1/X25519', $ephemeralShare . $recipient);
		$body = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($fileKey, '', str_repeat("\0", 12), $wrapKey);

		// Body is base64 wrapped at 64 columns; the final line must be shorter
		// than 64 characters (possibly empty).
		$encoded = self::b64($body);
		$lines = str_split($encoded, 64);
		if (strlen(end($lines)) === 64) {
			$lines[] = '';
		}
		return '-> X25519 ' . self::b64($ephemeralShare) . "\n" . implode("\n", $lines) . "\n";
	}

	public function write(string $data): void {
		$this->buffer .= $data;
		// Always keep at least one byte back: the final chunk must carry the
		// "last" flag, and we only know which one that is on close().
		while (strlen($this->buffer) > self::CHUNK_SIZE) {
			$this->emitChunk(substr($this->buffer, 0, self::CHUNK_SIZE), false);
			$this->buffer = substr($this->buffer, self::CHUNK_SIZE);
		}
	}

	public function close(): void {
		if ($this->closed) {
			return;
		}
		$this->closed = true;
		$this->emitChunk($this->buffer, true);
		$this->buffer = '';
		sodium_memzero($this->payloadKey);
		$this->next->close();
	}

	private function emitChunk(string $plaintext, bool $last): void {
		$nonce = substr(pack('J', 0), 0, 3) . pack('J', $this->counter) . ($last ? "\x01" : "\x00");
		$this->counter++;
		$this->next->write(sodium_crypto_aead_chacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->payloadKey));
	}

	private static function b64(string $data): string {
		return rtrim(base64_encode($data), '=');
	}
}
