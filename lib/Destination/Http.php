<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around the Nextcloud HTTP client that never throws on HTTP
 * error statuses and retries transient failures.
 */
class Http {
	private IClient $client;

	public function __construct(
		IClientService $clientService,
		private LoggerInterface $logger,
		private bool $allowLocalAddress = false,
	) {
		$this->client = $clientService->newClient();
	}

	/**
	 * Perform a request, retrying on connection errors, 429 and 5xx.
	 *
	 * @param callable(): array $optionsFactory builds the request options for
	 *                                         each attempt (bodies and
	 *                                         signatures may need rebuilding)
	 */
	public function request(string $method, string $uri, callable $optionsFactory, int $attempts = 5): IResponse {
		$delay = 1;
		for ($attempt = 1; ; $attempt++) {
			$options = $optionsFactory() + [
				'http_errors' => false,
				'allow_redirects' => false,
				'timeout' => 600,
				'connect_timeout' => 30,
				'nextcloud' => ['allow_local_address' => $this->allowLocalAddress],
			];
			try {
				$response = $this->client->request($method, $uri, $options);
				$status = $response->getStatusCode();
				if (($status !== 429 && $status < 500) || $attempt >= $attempts) {
					return $response;
				}
				$this->logger->info('Tagged backup: {method} request got HTTP {status}, retrying', [
					'method' => $method, 'status' => $status, 'app' => 'tagged_backup',
				]);
				$retryAfter = (int)$response->getHeader('Retry-After');
				if ($retryAfter > 0) {
					$delay = min($retryAfter, 120);
				}
			} catch (\Exception $e) {
				if ($attempt >= $attempts) {
					throw new DestinationException('Request failed: ' . $e->getMessage(), 0, $e);
				}
				$this->logger->info('Tagged backup: {method} request failed ({error}), retrying', [
					'method' => $method, 'error' => $e->getMessage(), 'app' => 'tagged_backup',
				]);
			}
			sleep($delay);
			$delay = min($delay * 2, 60);
		}
	}

	public static function body(IResponse $response): string {
		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}
		return (string)$body;
	}

	/**
	 * @return array<mixed>
	 */
	public static function json(IResponse $response): array {
		$data = json_decode(self::body($response), true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @throws DestinationException
	 */
	public static function expect(IResponse $response, array $statuses, string $what): void {
		$status = $response->getStatusCode();
		if (!in_array($status, $statuses, true)) {
			throw new DestinationException(sprintf('%s failed with HTTP %d: %s', $what, $status, self::errorDetail($response)));
		}
	}

	/**
	 * Extract a readable error from S3 XML or Google/Graph JSON error bodies.
	 */
	private static function errorDetail(IResponse $response): string {
		$body = trim(self::body($response));
		$json = json_decode($body, true);
		if (is_array($json)) {
			$error = $json['error'] ?? null;
			if (is_array($error)) {
				$detail = trim(($error['code'] ?? '') . ': ' . ($error['message'] ?? ''), ': ');
			} else {
				$detail = trim((string)$error . ': ' . ($json['error_description'] ?? ''), ': ');
			}
			if ($detail !== '') {
				return mb_strimwidth($detail, 0, 500, '…');
			}
		}
		if (str_starts_with($body, '<')) {
			$xml = @simplexml_load_string($body);
			if ($xml !== false && isset($xml->Code)) {
				return trim($xml->Code . ': ' . $xml->Message, ': ');
			}
		}
		return mb_strimwidth(trim(strip_tags($body)), 0, 500, '…');
	}

	/**
	 * Read exactly $length bytes from $handle starting at $offset.
	 *
	 * @param resource $handle
	 */
	public static function readChunk($handle, int $offset, int $length): string {
		if (fseek($handle, $offset) !== 0) {
			throw new DestinationException('Could not seek in backup file');
		}
		$data = '';
		while (strlen($data) < $length && !feof($handle)) {
			$read = fread($handle, $length - strlen($data));
			if ($read === false) {
				throw new DestinationException('Could not read backup file');
			}
			$data .= $read;
		}
		if (strlen($data) !== $length) {
			throw new DestinationException('Unexpected end of backup file');
		}
		return $data;
	}
}
