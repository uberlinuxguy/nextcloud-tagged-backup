<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

/**
 * S3 compatible object storage (AWS S3, MinIO, Backblaze B2, Wasabi, Ceph,
 * Cloudflare R2, ...) using AWS Signature Version 4 and multipart uploads.
 */
class S3Destination implements Destination {
	private const MIN_PART_SIZE = 16 * 1024 * 1024;
	private const MAX_PARTS = 10000;

	private string $endpoint;
	private string $region;
	private string $bucket;
	private string $prefix;
	private bool $pathStyle;

	public function __construct(
		private Http $http,
		private string $accessKey,
		private string $secretKey,
		string $endpoint,
		string $region,
		string $bucket,
		string $prefix,
		bool $pathStyle,
		private string $storageClass = '',
	) {
		$endpoint = trim($endpoint) === '' ? 'https://s3.amazonaws.com' : trim($endpoint);
		if (!preg_match('#^https?://#i', $endpoint)) {
			$endpoint = 'https://' . $endpoint;
		}
		$this->endpoint = rtrim($endpoint, '/');
		$this->region = trim($region) === '' ? 'us-east-1' : trim($region);
		$this->bucket = trim($bucket);
		$prefix = trim($prefix, " /");
		$this->prefix = $prefix === '' ? '' : $prefix . '/';
		$this->pathStyle = $pathStyle;
		if ($this->bucket === '') {
			throw new DestinationException('S3 bucket is not configured');
		}
	}

	public function test(): void {
		$key = $this->prefix . '.tagged-backup-write-test';
		$response = $this->send('PUT', $key, [], 'ok');
		Http::expect($response, [200], 'Test upload to S3');
		$response = $this->send('DELETE', $key);
		Http::expect($response, [200, 204], 'Deleting S3 test object');
	}

	public function upload(string $localPath, string $name, int $size): void {
		$key = $this->prefix . $name;
		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new DestinationException('Could not open backup file for upload');
		}
		try {
			$partSize = max(self::MIN_PART_SIZE, (int)ceil($size / self::MAX_PARTS / 1048576) * 1048576);
			if ($size <= $partSize) {
				$response = $this->send('PUT', $key, [], Http::readChunk($handle, 0, $size), $this->objectHeaders());
				Http::expect($response, [200], 'S3 upload');
				return;
			}
			$this->multipartUpload($handle, $key, $size, $partSize);
		} finally {
			fclose($handle);
		}
	}

	/**
	 * @param resource $handle
	 */
	private function multipartUpload($handle, string $key, int $size, int $partSize): void {
		$response = $this->send('POST', $key, ['uploads' => ''], '', $this->objectHeaders());
		Http::expect($response, [200], 'Starting S3 multipart upload');
		$uploadId = (string)$this->xml($response)->UploadId;
		if ($uploadId === '') {
			throw new DestinationException('S3 did not return a multipart upload id');
		}
		try {
			$parts = [];
			for ($offset = 0, $number = 1; $offset < $size; $offset += $partSize, $number++) {
				$data = Http::readChunk($handle, $offset, min($partSize, $size - $offset));
				$response = $this->send('PUT', $key, ['partNumber' => (string)$number, 'uploadId' => $uploadId], $data);
				Http::expect($response, [200], "S3 upload of part $number");
				$parts[$number] = $response->getHeader('ETag');
			}
			$xml = '<CompleteMultipartUpload>';
			foreach ($parts as $number => $etag) {
				$xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
			}
			$xml .= '</CompleteMultipartUpload>';
			$response = $this->send('POST', $key, ['uploadId' => $uploadId], $xml, ['Content-Type' => 'application/xml']);
			Http::expect($response, [200], 'Completing S3 multipart upload');
			// S3 may report an error in the body of a 200 response here.
			if (str_contains(Http::body($response), '<Error>')) {
				throw new DestinationException('Completing S3 multipart upload failed: ' . Http::body($response));
			}
		} catch (\Throwable $e) {
			try {
				$this->send('DELETE', $key, ['uploadId' => $uploadId]);
			} catch (\Throwable) {
				// best effort; bucket lifecycle rules should clean up the rest
			}
			throw $e;
		}
	}

	public function listFiles(): array {
		$files = [];
		$token = null;
		do {
			$query = ['list-type' => '2', 'prefix' => $this->prefix];
			if ($this->prefix !== '') {
				$query['delimiter'] = '/';
			}
			if ($token !== null) {
				$query['continuation-token'] = $token;
			}
			$response = $this->send('GET', '', $query);
			Http::expect($response, [200], 'Listing S3 bucket');
			$xml = $this->xml($response);
			foreach ($xml->Contents as $item) {
				$key = (string)$item->Key;
				$files[] = new RemoteFile($key, substr($key, strlen($this->prefix)));
			}
			$token = ((string)$xml->IsTruncated === 'true') ? (string)$xml->NextContinuationToken : null;
		} while ($token !== null && $token !== '');
		return $files;
	}

	public function delete(RemoteFile $file): void {
		$response = $this->send('DELETE', $file->id);
		Http::expect($response, [200, 204], 'Deleting S3 object');
	}

	/**
	 * @return array<string, string>
	 */
	private function objectHeaders(): array {
		$headers = ['Content-Type' => 'application/octet-stream'];
		if ($this->storageClass !== '') {
			$headers['x-amz-storage-class'] = $this->storageClass;
		}
		return $headers;
	}

	private function xml(\OCP\Http\Client\IResponse $response): \SimpleXMLElement {
		$xml = @simplexml_load_string(Http::body($response));
		if ($xml === false) {
			throw new DestinationException('Could not parse S3 response');
		}
		return $xml;
	}

	/**
	 * @param array<string, string> $query
	 * @param array<string, string> $headers
	 */
	private function send(string $method, string $key, array $query = [], string $body = '', array $headers = []): \OCP\Http\Client\IResponse {
		[$host, $path] = $this->hostAndPath($key);
		ksort($query, SORT_STRING);
		$canonicalQuery = implode('&', array_map(
			fn ($k, $v) => rawurlencode((string)$k) . '=' . rawurlencode($v),
			array_keys($query),
			$query,
		));
		$uri = $this->scheme() . '://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
		$payloadHash = hash('sha256', $body);

		return $this->http->request($method, $uri, function () use ($method, $host, $path, $canonicalQuery, $payloadHash, $body, $headers) {
			$amzDate = gmdate('Ymd\THis\Z');
			$date = substr($amzDate, 0, 8);
			$signed = array_change_key_case($headers, CASE_LOWER) + [
				'host' => $host,
				'x-amz-content-sha256' => $payloadHash,
				'x-amz-date' => $amzDate,
			];
			ksort($signed, SORT_STRING);
			$canonicalHeaders = '';
			foreach ($signed as $name => $value) {
				$canonicalHeaders .= $name . ':' . trim((string)$value) . "\n";
			}
			$signedHeaders = implode(';', array_keys($signed));
			$canonicalRequest = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);
			$scope = "$date/{$this->region}/s3/aws4_request";
			$stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
			$key = hash_hmac('sha256', 'aws4_request',
				hash_hmac('sha256', 's3',
					hash_hmac('sha256', $this->region,
						hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true), true), true), true);
			$signature = hash_hmac('sha256', $stringToSign, $key);

			$signed['Authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
			unset($signed['host']);
			$signed['Accept-Encoding'] = 'identity';
			return ['headers' => $signed, 'body' => $body];
		});
	}

	/**
	 * @return array{0: string, 1: string} [host header, canonical URI path]
	 */
	private function hostAndPath(string $key): array {
		$parts = parse_url($this->endpoint);
		$host = $parts['host'] ?? '';
		$defaultPort = ($parts['scheme'] ?? '') === 'http' ? 80 : 443;
		if (isset($parts['port']) && $parts['port'] !== $defaultPort) {
			$host .= ':' . $parts['port'];
		}
		$basePath = rtrim($parts['path'] ?? '', '/');
		$encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
		if ($this->pathStyle) {
			return [$host, $basePath . '/' . rawurlencode($this->bucket) . '/' . $encodedKey];
		}
		return [$this->bucket . '.' . $host, $basePath . '/' . $encodedKey];
	}

	private function scheme(): string {
		return strtolower((string)parse_url($this->endpoint, PHP_URL_SCHEME));
	}
}
