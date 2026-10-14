<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Destination;

use OCA\TaggedBackup\Db\Job;
use OCA\TaggedBackup\Service\JobService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class DestinationFactory {
	public const TYPES = ['s3', 'gdrive', 'gdrive_device', 'onedrive'];

	/**
	 * Configuration fields per destination type. Secret fields are never sent
	 * back to the browser; "oauth" fields are only set by the OAuth flow.
	 */
	public const FIELDS = [
		's3' => [
			'endpoint' => 'text',
			'region' => 'text',
			'bucket' => 'text',
			'prefix' => 'text',
			'path_style' => 'bool',
			'storage_class' => 'text',
			'access_key' => 'text',
			'secret_key' => 'secret',
			'allow_local' => 'bool',
		],
		'gdrive' => [
			'client_id' => 'text',
			'client_secret' => 'secret',
			'folder_name' => 'text',
			'refresh_token' => 'oauth',
		],
		// Client ID/secret are optional here: an instance-wide or built-in
		// client is used when they are empty (see GoogleDeviceFlow).
		'gdrive_device' => [
			'client_id' => 'text',
			'client_secret' => 'secret',
			'folder_name' => 'text',
			'refresh_token' => 'oauth',
		],
		'onedrive' => [
			'tenant' => 'text',
			'client_id' => 'text',
			'client_secret' => 'secret',
			'folder_path' => 'text',
			'refresh_token' => 'oauth',
		],
	];

	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
		private JobService $jobService,
		private GoogleDeviceFlow $googleDeviceFlow,
	) {
	}

	public function http(bool $allowLocalAddress = false): Http {
		return new Http($this->clientService, $this->logger, $allowLocalAddress);
	}

	/**
	 * @throws DestinationException
	 */
	public function create(Job $job): Destination {
		$config = $this->jobService->getDestinationConfig($job);
		$str = static fn (string $key): string => trim((string)($config[$key] ?? ''));
		$persistRefreshToken = function (string $token) use ($job): void {
			$this->jobService->storeRefreshToken($job->getId(), $token);
		};

		return match ($job->getDestinationType()) {
			's3' => new S3Destination(
				$this->http((bool)($config['allow_local'] ?? false)),
				$str('access_key'),
				(string)($config['secret_key'] ?? ''),
				$str('endpoint'),
				$str('region'),
				$str('bucket'),
				$str('prefix'),
				(bool)($config['path_style'] ?? true),
				$str('storage_class'),
			),
			'gdrive' => new GoogleDriveDestination(
				$this->http(),
				$str('client_id'),
				$str('client_secret'),
				$str('refresh_token'),
				$persistRefreshToken,
				$str('folder_name'),
			),
			'gdrive_device' => new GoogleDriveDestination(
				$this->http(),
				$this->googleDeviceFlow->client($config)[0],
				$this->googleDeviceFlow->client($config)[1],
				$str('refresh_token'),
				$persistRefreshToken,
				$str('folder_name'),
			),
			'onedrive' => new OneDriveDestination(
				$this->http(),
				$str('tenant'),
				$str('client_id'),
				$str('client_secret'),
				$str('refresh_token'),
				$persistRefreshToken,
				$str('folder_path'),
			),
			default => throw new DestinationException('Unknown destination type ' . $job->getDestinationType()),
		};
	}

	public function oauthProvider(Job $job): ?OAuthProvider {
		$config = $this->jobService->getDestinationConfig($job);
		return match ($job->getDestinationType()) {
			'gdrive' => OAuthProvider::google(),
			'onedrive' => OAuthProvider::microsoft((string)($config['tenant'] ?? '')),
			default => null,
		};
	}
}
