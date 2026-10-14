<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

/**
 * Google Drive via the Drive v3 API. Uses the drive.file scope, so the app
 * can only see files and folders it created itself; the target folder is
 * created on first use.
 */
class GoogleDriveDestination extends OAuthDestination {
	private const API = 'https://www.googleapis.com/drive/v3';
	private const UPLOAD_API = 'https://www.googleapis.com/upload/drive/v3';
	private const CHUNK_SIZE = 16 * 1024 * 1024; // must be a multiple of 256 KiB
	private const FOLDER_MIME = 'application/vnd.google-apps.folder';

	private ?string $folderId = null;

	public function __construct(
		Http $http,
		string $clientId,
		string $clientSecret,
		string $refreshToken,
		callable $onRefreshTokenRotated,
		private string $folderName,
	) {
		parent::__construct($http, OAuthProvider::google(), $clientId, $clientSecret, $refreshToken, $onRefreshTokenRotated);
		$this->folderName = trim($folderName) === '' ? 'Nextcloud Tagged Backups' : trim($folderName);
	}

	public function test(): void {
		$this->folderId();
	}

	private function folderId(): string {
		if ($this->folderId !== null) {
			return $this->folderId;
		}
		$query = sprintf("mimeType = '%s' and name = '%s' and 'root' in parents and trashed = false",
			self::FOLDER_MIME, addcslashes($this->folderName, "\\'"));
		$response = $this->api('GET', self::API . '/files?' . http_build_query([
			'q' => $query, 'fields' => 'files(id,name)', 'spaces' => 'drive',
		]));
		Http::expect($response, [200], 'Looking up Google Drive folder');
		$files = Http::json($response)['files'] ?? [];
		if ($files !== []) {
			return $this->folderId = $files[0]['id'];
		}
		$response = $this->api('POST', self::API . '/files?fields=id', [
			'json' => ['name' => $this->folderName, 'mimeType' => self::FOLDER_MIME],
		]);
		Http::expect($response, [200], 'Creating Google Drive folder');
		return $this->folderId = Http::json($response)['id'];
	}

	public function upload(string $localPath, string $name, int $size): void {
		$response = $this->api('POST', self::UPLOAD_API . '/files?uploadType=resumable&fields=id', [
			'json' => ['name' => $name, 'parents' => [$this->folderId()], 'mimeType' => 'application/octet-stream'],
			'headers' => ['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string)$size],
		]);
		Http::expect($response, [200], 'Starting Google Drive upload');
		$sessionUri = $response->getHeader('Location');
		if ($sessionUri === '') {
			throw new DestinationException('Google Drive did not return an upload session');
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
					// No automatic retries: after a failure we must ask the
					// server how much it received before sending more.
					$response = $this->api('PUT', $sessionUri, [
						'body' => $length > 0 ? Http::readChunk($handle, $offset, $length) : '',
						'headers' => [
							'Content-Range' => $length > 0 ? sprintf('bytes %d-%d/%d', $offset, $offset + $length - 1, $size) : "bytes */$size",
						],
					], 1);
					$status = $response->getStatusCode();
				} catch (DestinationException $e) {
					$status = 0;
				}
				if ($status === 200 || $status === 201) {
					return;
				}
				if ($status === 308) {
					$offset = $this->receivedBytes($response->getHeader('Range'));
					$failures = 0;
					continue;
				}
				if (($status >= 400 && $status < 500 && $status !== 429) || ++$failures > 5) {
					if ($status === 0) {
						throw $e;
					}
					Http::expect($response, [200, 201], 'Google Drive upload');
				}
				sleep(2 ** $failures);
				$probe = $this->api('PUT', $sessionUri, ['headers' => ['Content-Range' => "bytes */$size"]]);
				if (in_array($probe->getStatusCode(), [200, 201], true)) {
					return;
				}
				Http::expect($probe, [308], 'Resuming Google Drive upload');
				$offset = $this->receivedBytes($probe->getHeader('Range'));
			}
		} finally {
			fclose($handle);
		}
	}

	private function receivedBytes(string $rangeHeader): int {
		if (preg_match('/bytes=0-(\d+)/', $rangeHeader, $m)) {
			return (int)$m[1] + 1;
		}
		return 0;
	}

	public function listFiles(): array {
		$files = [];
		$pageToken = null;
		do {
			$params = [
				'q' => sprintf("'%s' in parents and trashed = false", $this->folderId()),
				'fields' => 'nextPageToken,files(id,name)',
				'pageSize' => 1000,
			];
			if ($pageToken !== null) {
				$params['pageToken'] = $pageToken;
			}
			$response = $this->api('GET', self::API . '/files?' . http_build_query($params));
			Http::expect($response, [200], 'Listing Google Drive folder');
			$data = Http::json($response);
			foreach ($data['files'] ?? [] as $file) {
				$files[] = new RemoteFile($file['id'], $file['name']);
			}
			$pageToken = $data['nextPageToken'] ?? null;
		} while ($pageToken !== null);
		return $files;
	}

	public function delete(RemoteFile $file): void {
		$response = $this->api('DELETE', self::API . '/files/' . rawurlencode($file->id));
		Http::expect($response, [204, 200, 404], 'Deleting Google Drive file');
	}
}
