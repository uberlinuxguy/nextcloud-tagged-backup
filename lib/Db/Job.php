<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getName()
 * @method void setName(string $name)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getTagIds()
 * @method void setTagIds(string $tagIds)
 * @method string|null getScopeUser()
 * @method void setScopeUser(?string $scopeUser)
 * @method string getCompression()
 * @method void setCompression(string $compression)
 * @method string|null getAgeRecipients()
 * @method void setAgeRecipients(?string $ageRecipients)
 * @method string getDestinationType()
 * @method void setDestinationType(string $destinationType)
 * @method string|null getDestinationConfig()
 * @method void setDestinationConfig(?string $destinationConfig)
 * @method int getIntervalHours()
 * @method void setIntervalHours(int $intervalHours)
 * @method int getRetentionCount()
 * @method void setRetentionCount(int $retentionCount)
 * @method int|null getLastRunAt()
 * @method void setLastRunAt(?int $lastRunAt)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method string|null getLastMessage()
 * @method void setLastMessage(?string $lastMessage)
 * @method int|null getLastSize()
 * @method void setLastSize(?int $lastSize)
 * @method int|null getLastFileCount()
 * @method void setLastFileCount(?int $lastFileCount)
 * @method int|null getRunningSince()
 * @method void setRunningSince(?int $runningSince)
 */
class Job extends Entity {
	public const STATUS_SUCCESS = 'success';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_FAILED = 'failed';

	protected $name = '';
	protected $enabled = true;
	protected $tagIds = '[]';
	protected $scopeUser;
	protected $compression = 'gzip';
	protected $ageRecipients;
	protected $destinationType = '';
	protected $destinationConfig;
	protected $intervalHours = 24;
	protected $retentionCount = 0;
	protected $lastRunAt;
	protected $lastStatus;
	protected $lastMessage;
	protected $lastSize;
	protected $lastFileCount;
	protected $runningSince;

	public function __construct() {
		$this->addType('enabled', 'boolean');
		$this->addType('intervalHours', 'integer');
		$this->addType('retentionCount', 'integer');
		$this->addType('lastRunAt', 'integer');
		$this->addType('lastSize', 'integer');
		$this->addType('lastFileCount', 'integer');
		$this->addType('runningSince', 'integer');
	}

	/**
	 * @return string[]
	 */
	public function getTagIdList(): array {
		$ids = json_decode($this->getTagIds(), true);
		return is_array($ids) ? array_map('strval', $ids) : [];
	}

	public function isEncrypted(): bool {
		return trim((string)$this->getAgeRecipients()) !== '';
	}

	/**
	 * Name prefix used for this job's backup files, also used to find old
	 * backups when applying retention.
	 */
	public function getFilePrefix(): string {
		$slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $this->getName()), '-'));
		return ($slug === '' ? 'backup' : $slug) . '-' . $this->getId();
	}
}
