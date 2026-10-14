<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Command;

use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Service\BackupRunner;
use OCA\TaggedBackup\Service\FileCollector;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Preview extends Command {
	public function __construct(
		private JobMapper $mapper,
		private FileCollector $collector,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('tagged-backup:preview')
			->setDescription('List the files a tagged backup job would include')
			->addArgument('id', InputArgument::REQUIRED, 'Job ID (see tagged-backup:list)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$job = $this->mapper->find((int)$input->getArgument('id'));
		} catch (DoesNotExistException) {
			$output->writeln('<error>No such job</error>');
			return 1;
		}
		$collected = $this->collector->collect($job);
		$bytes = 0;
		foreach ($collected['entries'] as $entry) {
			$size = $entry->isDirectory ? 0 : (int)$entry->node->getSize();
			$bytes += $size;
			$output->writeln(sprintf('%12s  %s%s', $entry->isDirectory ? '' : BackupRunner::formatBytes($size), $entry->archivePath, $entry->isDirectory ? '/' : ''));
		}
		foreach ($collected['missing'] as $missing) {
			$output->writeln('<comment>skipped file id ' . $missing['fileId'] . ': ' . $missing['reason'] . '</comment>');
		}
		if ($collected['unreachable'] !== []) {
			$output->writeln(sprintf('<comment>%d tagged file id(s) are not in any user\'s files (deleted, trashed or out of scope)</comment>', count($collected['unreachable'])));
		}
		$output->writeln(sprintf('%d entries, %s', count($collected['entries']), BackupRunner::formatBytes($bytes)));
		return 0;
	}
}
