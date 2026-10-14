<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

use OCA\TaggedBackup\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Google's OAuth 2.0 device authorization flow ("TVs and Limited Input
 * devices"): the admin enters a short code at google.com/device, so no
 * redirect URI is needed and one client ID can be shared by every install.
 *
 * The client used is, in order of preference: the one entered in the job,
 * the instance default (occ config:app:set tagged_backup
 * gdrive_device_client_id / gdrive_device_client_secret), or the built-in
 * one below.
 */
class GoogleDeviceFlow {
	public const DEVICE_CODE_URL = 'https://oauth2.googleapis.com/device/code';
	public const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

	/**
	 * Built-in client for distributed builds of the app. Device-flow client
	 * secrets are not confidential (Google treats such clients as installed
	 * apps), so shipping them in source is acceptable.
	 */
	private const BUILTIN_CLIENT_ID = '573910254167-h2us3mgijkb4ieep9141ia53dtl7tln6.apps.googleusercontent.com';
	private const BUILTIN_CLIENT_SECRET = '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';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * @param array<string, mixed> $config job destination config
	 * @return array{0: string, 1: string} [client id, client secret], empty if none configured
	 */
	public function client(array $config): array {
		$id = trim((string)($config['client_id'] ?? ''));
		if ($id !== '') {
			return [$id, (string)($config['client_secret'] ?? '')];
		}
		$id = $this->appConfig->getValueString(Application::APP_ID, 'gdrive_device_client_id', '');
		if ($id !== '') {
			return [$id, $this->appConfig->getValueString(Application::APP_ID, 'gdrive_device_client_secret', '')];
		}
		return [self::BUILTIN_CLIENT_ID, self::BUILTIN_CLIENT_SECRET];
	}

	public function hasDefaultClient(): bool {
		return $this->client([])[0] !== '';
	}

	/**
	 * Request a device and user code.
	 *
	 * @return array{device_code: string, user_code: string, verification_url: string, expires_in: int, interval: int}
	 * @throws DestinationException
	 */
	public static function start(Http $http, string $clientId): array {
		$response = $http->request('POST', self::DEVICE_CODE_URL, fn () => ['form_params' => [
			'client_id' => $clientId,
			'scope' => OAuthProvider::google()->scope,
		]]);
		$data = Http::json($response);
		if ($response->getStatusCode() !== 200 || !isset($data['device_code'], $data['user_code'])) {
			$error = $data['error_description'] ?? $data['error'] ?? Http::body($response);
			throw new DestinationException('Google device sign-in failed: ' . (is_string($error) ? $error : json_encode($error)));
		}
		return [
			'device_code' => (string)$data['device_code'],
			'user_code' => (string)$data['user_code'],
			'verification_url' => (string)($data['verification_url'] ?? $data['verification_uri'] ?? 'https://www.google.com/device'),
			'expires_in' => (int)($data['expires_in'] ?? 1800),
			'interval' => max(1, (int)($data['interval'] ?? 5)),
		];
	}

	/**
	 * Ask once whether the user has approved the request.
	 *
	 * @return array{status: 'pending'|'slow_down'|'done', refresh_token?: string}
	 * @throws DestinationException when the request was denied, expired or failed
	 */
	public static function poll(Http $http, string $clientId, string $clientSecret, string $deviceCode): array {
		$response = $http->request('POST', OAuthProvider::google()->tokenUrl, fn () => ['form_params' => [
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'device_code' => $deviceCode,
			'grant_type' => self::GRANT_TYPE,
		]]);
		$data = Http::json($response);
		if ($response->getStatusCode() === 200 && isset($data['access_token'])) {
			if (($data['refresh_token'] ?? '') === '') {
				throw new DestinationException('Google did not issue a refresh token');
			}
			return ['status' => 'done', 'refresh_token' => (string)$data['refresh_token']];
		}
		$error = (string)($data['error'] ?? '');
		return match ($error) {
			'authorization_pending' => ['status' => 'pending'],
			'slow_down' => ['status' => 'slow_down'],
			'access_denied' => throw new DestinationException('Sign-in was declined'),
			'expired_token' => throw new DestinationException('The code expired, please start again'),
			default => throw new DestinationException('Google device sign-in failed: '
				. ($data['error_description'] ?? ($error !== '' ? $error : 'HTTP ' . $response->getStatusCode()))),
		};
	}
}
