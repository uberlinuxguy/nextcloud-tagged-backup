<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\BackgroundJob;

use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Service\BackupRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * One-off run of a backup job, queued by "Run now".
 */
class RunBackup extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private JobMapper $mapper,
		private BackupRunner $runner,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		try {
			$job = $this->mapper->find((int)($argument['jobId'] ?? 0));
		} catch (DoesNotExistException) {
			return;
		}
		$this->runner->run($job);
	}
}
