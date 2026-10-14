<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

/**
 * OAuth 2.0 authorization code flow details for a cloud drive provider.
 */
class OAuthProvider {
	public function __construct(
		public readonly string $authorizeUrl,
		public readonly string $tokenUrl,
		public readonly string $scope,
		/** @var array<string, string> */
		public readonly array $extraAuthorizeParams = [],
		public readonly bool $sendScopeToTokenEndpoint = false,
	) {
	}

	public static function google(): self {
		return new self(
			'https://accounts.google.com/o/oauth2/v2/auth',
			'https://oauth2.googleapis.com/token',
			'https://www.googleapis.com/auth/drive.file',
			['access_type' => 'offline', 'prompt' => 'consent'],
		);
	}

	public static function microsoft(string $tenant): self {
		$tenant = rawurlencode(trim($tenant) === '' ? 'common' : trim($tenant));
		return new self(
			"https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
			"https://login.microsoftonline.com/$tenant/oauth2/v2.0/token",
			'offline_access Files.ReadWrite',
			['response_mode' => 'query', 'prompt' => 'select_account'],
			true,
		);
	}

	public function buildAuthorizeUrl(string $clientId, string $redirectUri, string $state): string {
		return $this->authorizeUrl . '?' . http_build_query([
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'response_type' => 'code',
			'scope' => $this->scope,
			'state' => $state,
		] + $this->extraAuthorizeParams, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * @param array<string, string> $params
	 * @return array{access_token: string, refresh_token?: string, expires_in?: int}
	 * @throws DestinationException
	 */
	public function requestToken(Http $http, string $clientId, string $clientSecret, array $params): array {
		$params += ['client_id' => $clientId, 'client_secret' => $clientSecret];
		if ($this->sendScopeToTokenEndpoint) {
			$params['scope'] = $this->scope;
		}
		$response = $http->request('POST', $this->tokenUrl, fn () => ['form_params' => $params]);
		$data = Http::json($response);
		if ($response->getStatusCode() !== 200 || !isset($data['access_token'])) {
			$error = $data['error_description'] ?? $data['error'] ?? Http::body($response);
			throw new DestinationException('OAuth token request failed: ' . (is_string($error) ? $error : json_encode($error)));
		}
		return $data;
	}
}
