<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Command;

use OCA\TaggedBackup\Db\Job;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Service\BackupRunner;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunJob extends Command {
	public function __construct(
		private JobMapper $mapper,
		private BackupRunner $runner,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('tagged-backup:run')
			->setDescription('Run a tagged backup job now')
			->addArgument('id', InputArgument::REQUIRED, 'Job ID (see tagged-backup:list)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$job = $this->mapper->find((int)$input->getArgument('id'));
		} catch (DoesNotExistException) {
			$output->writeln('<error>No such job</error>');
			return 1;
		}
		$result = $this->runner->run($job, fn (string $line) => $output->writeln($line));
		return match ($result['status']) {
			Job::STATUS_SUCCESS => 0,
			Job::STATUS_PARTIAL => 2,
			default => 1,
		};
	}
}
