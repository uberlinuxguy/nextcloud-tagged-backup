<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Service;

use OCA\TaggedBackup\Crypto\AgeRecipients;
use OCA\TaggedBackup\Db\Job;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Destination\DestinationFactory;
use OCA\TaggedBackup\Destination\GoogleDeviceFlow;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\TagNotFoundException;

class JobService {
	public const COMPRESSIONS = ['none', 'gzip', 'bzip2'];

	public function __construct(
		private JobMapper $mapper,
		private ICrypto $crypto,
		private ISystemTagManager $tagManager,
		private IUserManager $userManager,
		private GoogleDeviceFlow $googleDeviceFlow,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDestinationConfig(Job $job): array {
		$stored = $job->getDestinationConfig();
		if ($stored === null || $stored === '') {
			return [];
		}
		$config = json_decode($this->crypto->decrypt($stored), true);
		return is_array($config) ? $config : [];
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function setDestinationConfig(Job $job, array $config): void {
		$job->setDestinationConfig($this->crypto->encrypt(json_encode($config, JSON_THROW_ON_ERROR)));
	}

	public function storeRefreshToken(int $jobId, string $refreshToken): void {
		// Reload so we only touch the destination config column.
		$job = $this->mapper->find($jobId);
		$config = $this->getDestinationConfig($job);
		$config['refresh_token'] = $refreshToken;
		$this->setDestinationConfig($job, $config);
		$this->mapper->update($job);
	}

	/**
	 * Apply user supplied settings to a job (new or existing) and save it.
	 *
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public function save(?Job $job, array $data): Job {
		$isNew = $job === null;
		$job ??= new Job();

		$name = trim((string)($data['name'] ?? ''));
		if ($name === '' || mb_strlen($name) > 128) {
			throw new \InvalidArgumentException('Name is required (at most 128 characters)');
		}
		$job->setName($name);
		$job->setEnabled((bool)($data['enabled'] ?? true));

		$tagIds = array_values(array_unique(array_map('strval', (array)($data['tagIds'] ?? []))));
		if ($tagIds === []) {
			throw new \InvalidArgumentException('Select at least one tag');
		}
		try {
			$this->tagManager->getTagsByIds($tagIds);
		} catch (TagNotFoundException|\InvalidArgumentException $e) {
			throw new \InvalidArgumentException('Unknown tag selected');
		}
		$job->setTagIds(json_encode($tagIds));

		$scopeUser = trim((string)($data['scopeUser'] ?? ''));
		if ($scopeUser !== '' && !$this->userManager->userExists($scopeUser)) {
			throw new \InvalidArgumentException('User "' . $scopeUser . '" does not exist');
		}
		$job->setScopeUser($scopeUser === '' ? null : $scopeUser);

		$compression = (string)($data['compression'] ?? 'gzip');
		if (!in_array($compression, self::COMPRESSIONS, true)) {
			throw new \InvalidArgumentException('Unknown compression ' . $compression);
		}
		if ($compression === 'bzip2' && !in_array('bzip2.*', stream_get_filters(), true)) {
			throw new \InvalidArgumentException('bzip2 needs the PHP bz2 extension, which is not installed');
		}
		$job->setCompression($compression);

		$recipients = trim((string)($data['ageRecipients'] ?? ''));
		if ($recipients !== '') {
			AgeRecipients::parse($recipients);
		}
		$job->setAgeRecipients($recipients === '' ? null : $recipients);

		$interval = (int)($data['intervalHours'] ?? 24);
		$retention = (int)($data['retentionCount'] ?? 0);
		if ($interval < 0 || $retention < 0) {
			throw new \InvalidArgumentException('Schedule and retention must not be negative');
		}
		$job->setIntervalHours($interval);
		$job->setRetentionCount($retention);

		$type = (string)($data['destinationType'] ?? '');
		if (!in_array($type, DestinationFactory::TYPES, true)) {
			throw new \InvalidArgumentException('Choose a destination');
		}
		$oldConfig = (!$isNew && $job->getDestinationType() === $type) ? $this->getDestinationConfig($job) : [];
		$input = (array)($data['destination'] ?? []);
		$config = [];
		foreach (DestinationFactory::FIELDS[$type] as $field => $kind) {
			$config[$field] = match ($kind) {
				'bool' => (bool)($input[$field] ?? false),
				// Empty secret input means "keep the stored value".
				'secret' => (string)($input[$field] ?? '') !== '' ? (string)$input[$field] : ($oldConfig[$field] ?? ''),
				'oauth' => $oldConfig[$field] ?? '',
				default => trim((string)($input[$field] ?? '')),
			};
		}
		// A different OAuth client cannot use the old client's refresh token.
		if (isset($oldConfig['client_id']) && $oldConfig['client_id'] !== ($config['client_id'] ?? null)) {
			$config['refresh_token'] = '';
		}
		if ($type === 'gdrive_device' && $this->googleDeviceFlow->client($config)[0] === '') {
			throw new \InvalidArgumentException('Enter an OAuth client ID (no default client is configured for this server)');
		}
		$job->setDestinationType($type);
		$this->setDestinationConfig($job, $config);

		return $isNew ? $this->mapper->insert($job) : $this->mapper->update($job);
	}

	/**
	 * Serialize a job for the admin UI, without secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(Job $job): array {
		$config = $this->getDestinationConfig($job);
		$destination = [];
		foreach (DestinationFactory::FIELDS[$job->getDestinationType()] ?? [] as $field => $kind) {
			if ($kind === 'secret' || $kind === 'oauth') {
				$destination[$field . '_set'] = (string)($config[$field] ?? '') !== '';
			} else {
				$destination[$field] = $config[$field] ?? ($kind === 'bool' ? false : '');
			}
		}
		return [
			'id' => $job->getId(),
			'name' => $job->getName(),
			'enabled' => $job->getEnabled(),
			'tagIds' => $job->getTagIdList(),
			'scopeUser' => $job->getScopeUser() ?? '',
			'compression' => $job->getCompression(),
			'ageRecipients' => $job->getAgeRecipients() ?? '',
			'destinationType' => $job->getDestinationType(),
			'destination' => $destination,
			'intervalHours' => $job->getIntervalHours(),
			'retentionCount' => $job->getRetentionCount(),
			'lastRunAt' => $job->getLastRunAt(),
			'lastStatus' => $job->getLastStatus(),
			'lastMessage' => $job->getLastMessage(),
			'lastSize' => $job->getLastSize(),
			'lastFileCount' => $job->getLastFileCount(),
			'running' => $job->getRunningSince() !== null,
		];
	}
}
