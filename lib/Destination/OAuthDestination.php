<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

/**
 * Base class for destinations authenticated with an OAuth refresh token.
 */
abstract class OAuthDestination implements Destination {
	private ?string $accessToken = null;
	private int $accessTokenExpires = 0;

	/**
	 * @param callable(string): void $onRefreshTokenRotated persists a new
	 *                                                      refresh token
	 */
	public function __construct(
		protected Http $http,
		private OAuthProvider $provider,
		private string $clientId,
		private string $clientSecret,
		private string $refreshToken,
		private $onRefreshTokenRotated,
	) {
		if ($this->clientId === '' || $this->clientSecret === '') {
			throw new DestinationException('OAuth client ID and secret are not configured');
		}
		if ($this->refreshToken === '') {
			throw new DestinationException('Account is not connected yet. Use "Connect account" in the backup job settings.');
		}
	}

	protected function accessToken(): string {
		if ($this->accessToken === null || time() >= $this->accessTokenExpires - 120) {
			$data = $this->provider->requestToken($this->http, $this->clientId, $this->clientSecret, [
				'grant_type' => 'refresh_token',
				'refresh_token' => $this->refreshToken,
			]);
			$this->accessToken = $data['access_token'];
			$this->accessTokenExpires = time() + (int)($data['expires_in'] ?? 3600);
			$newRefreshToken = $data['refresh_token'] ?? '';
			if ($newRefreshToken !== '' && $newRefreshToken !== $this->refreshToken) {
				$this->refreshToken = $newRefreshToken;
				($this->onRefreshTokenRotated)($newRefreshToken);
			}
		}
		return $this->accessToken;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	protected function api(string $method, string $uri, array $options = [], int $attempts = 5): \OCP\Http\Client\IResponse {
		return $this->http->request($method, $uri, function () use ($options) {
			$options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $this->accessToken()];
			return $options;
		}, $attempts);
	}
}
