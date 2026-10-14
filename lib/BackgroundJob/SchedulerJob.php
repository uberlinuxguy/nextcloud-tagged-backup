<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\BackgroundJob;

use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Service\BackupRunner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Runs every backup job whose schedule interval has elapsed.
 */
class SchedulerJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private JobMapper $mapper,
		private BackupRunner $runner,
	) {
		parent::__construct($time);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		$now = $this->time->getTime();
		foreach ($this->mapper->findAll() as $job) {
			$interval = $job->getIntervalHours();
			if (!$job->getEnabled() || $interval <= 0 || $job->getRunningSince() !== null) {
				continue;
			}
			// A few minutes of slack so a daily job does not drift by one
			// cron interval every day.
			$due = ($job->getLastRunAt() ?? 0) + $interval * 3600 - 10 * 60;
			if ($now >= $due) {
				$this->runner->run($job);
			}
		}
	}
}
