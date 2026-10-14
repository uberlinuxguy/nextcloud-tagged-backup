<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Command;

use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Service\BackupRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListJobs extends Command {
	public function __construct(
		private JobMapper $mapper,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('tagged-backup:list')
			->setDescription('List tagged backup jobs');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$table = new Table($output);
		$table->setHeaders(['ID', 'Name', 'Enabled', 'Destination', 'Every', 'Last run', 'Status', 'Size']);
		foreach ($this->mapper->findAll() as $job) {
			$table->addRow([
				$job->getId(),
				$job->getName(),
				$job->getEnabled() ? 'yes' : 'no',
				$job->getDestinationType(),
				$job->getIntervalHours() > 0 ? $job->getIntervalHours() . 'h' : 'manual',
				$job->getLastRunAt() ? gmdate('Y-m-d H:i', $job->getLastRunAt()) . ' UTC' : '-',
				$job->getRunningSince() !== null ? 'running' : ($job->getLastStatus() ?? '-'),
				$job->getLastSize() !== null ? BackupRunner::formatBytes($job->getLastSize()) : '-',
			]);
		}
		$table->render();
		return 0;
	}
}
