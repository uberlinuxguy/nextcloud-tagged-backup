<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

/**
 * Microsoft OneDrive (personal or business) via Microsoft Graph.
 */
class OneDriveDestination extends OAuthDestination {
	private const API = 'https://graph.microsoft.com/v1.0/me/drive';
	private const CHUNK_SIZE = 32 * 327680; // 10 MiB, must be a multiple of 320 KiB

	private string $folderPath;

	public function __construct(
		Http $http,
		string $tenant,
		string $clientId,
		string $clientSecret,
		string $refreshToken,
		callable $onRefreshTokenRotated,
		string $folderPath,
	) {
		parent::__construct($http, OAuthProvider::microsoft($tenant), $clientId, $clientSecret, $refreshToken, $onRefreshTokenRotated);
		$folderPath = trim($folderPath, " /");
		$this->folderPath = $folderPath === '' ? 'Nextcloud Tagged Backups' : $folderPath;
	}

	private function itemPath(string $name = ''): string {
		$path = $this->folderPath . ($name === '' ? '' : '/' . $name);
		return self::API . '/root:/' . implode('/', array_map('rawurlencode', explode('/', $path))) . ':';
	}

	public function test(): void {
		$this->ensureFolder();
	}

	private function ensureFolder(): void {
		$response = $this->api('GET', $this->itemPath());
		if ($response->getStatusCode() === 200) {
			return;
		}
		Http::expect($response, [404], 'Looking up OneDrive folder');
		// Create each missing path segment.
		$parent = self::API . '/root';
		$segments = explode('/', $this->folderPath);
		$path = '';
		foreach ($segments as $segment) {
			$path .= ($path === '' ? '' : '/') . $segment;
			$encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
			$response = $this->api('GET', self::API . '/root:/' . $encoded);
			if ($response->getStatusCode() !== 200) {
				$response = $this->api('POST', $parent . '/children', ['json' => [
					'name' => $segment,
					'folder' => new \stdClass(),
					'@microsoft.graph.conflictBehavior' => 'fail',
				]]);
				Http::expect($response, [201, 409], 'Creating OneDrive folder');
			}
			$parent = self::API . '/root:/' . $encoded . ':';
		}
	}

	public function upload(string $localPath, string $name, int $size): void {
		$this->ensureFolder();
		$response = $this->api('POST', $this->itemPath($name) . '/createUploadSession', ['json' => [
			'item' => ['@microsoft.graph.conflictBehavior' => 'replace'],
		]]);
		Http::expect($response, [200], 'Starting OneDrive upload');
		$uploadUrl = Http::json($response)['uploadUrl'] ?? '';
		if ($uploadUrl === '') {
			throw new DestinationException('OneDrive did not return an upload URL');
		}

		$handle = fopen($localPath, 'rb');
		if ($handle === false) {
			throw new DestinationException('Could not open backup file for upload');
		}
		try {
			$offset = 0;
			$failures = 0;
			while (true) {
				$length = min(self::CHUNK_SIZE, $size - $offset);
				try {
					// The pre-authenticated upload URL must not receive the
					// bearer token. No automatic retries: after a failure we
					// must ask the server which range it expects next.
					$response = $this->http->request('PUT', $uploadUrl, fn () => [
						'body' => Http::readChunk($handle, $offset, $length),
						'headers' => ['Content-Range' => sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size)],
					], 1);
					$status = $response->getStatusCode();
				} catch (DestinationException $e) {
					$status = 0;
				}
				if ($status === 200 || $status === 201) {
					return;
				}
				if ($status === 202) {
					$offset += $length;
					$failures = 0;
					continue;
				}
				if ($status === 404 || ++$failures > 5) {
					if ($status === 0) {
						throw $e;
					}
					Http::expect($response, [200, 201, 202], 'OneDrive upload');
				}
				sleep(2 ** $failures);
				$probe = $this->http->request('GET', $uploadUrl, fn () => []);
				Http::expect($probe, [200], 'Resuming OneDrive upload');
				$ranges = Http::json($probe)['nextExpectedRanges'] ?? [];
				if (!isset($ranges[0]) || !preg_match('/^(\d+)-/', $ranges[0], $m)) {
					throw new DestinationException('OneDrive upload session lost');
				}
				$offset = (int)$m[1];
			}
		} finally {
			fclose($handle);
		}
	}

	public function listFiles(): array {
		$files = [];
		$url = $this->itemPath() . '/children?$select=id,name&$top=1000';
		while ($url !== null) {
			$response = $this->api('GET', $url);
			if ($response->getStatusCode() === 404) {
				return [];
			}
			Http::expect($response, [200], 'Listing OneDrive folder');
			$data = Http::json($response);
			foreach ($data['value'] ?? [] as $item) {
				$files[] = new RemoteFile($item['id'], $item['name']);
			}
			$url = $data['@odata.nextLink'] ?? null;
		}
		return $files;
	}

	public function delete(RemoteFile $file): void {
		$response = $this->api('DELETE', self::API . '/items/' . rawurlencode($file->id));
		Http::expect($response, [204, 404], 'Deleting OneDrive file');
	}
}
