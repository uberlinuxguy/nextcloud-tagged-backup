<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Controller;

use OCA\TaggedBackup\AppInfo\Application;
use OCA\TaggedBackup\BackgroundJob\RunBackup;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Destination\DestinationFactory;
use OCA\TaggedBackup\Service\FileCollector;
use OCA\TaggedBackup\Service\JobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\SystemTag\ISystemTagManager;

/**
 * Admin-only API for the settings page (no NoAdminRequired attribute).
 */
class JobController extends Controller {
	public function __construct(
		IRequest $request,
		private JobMapper $mapper,
		private JobService $jobService,
		private DestinationFactory $destinationFactory,
		private FileCollector $collector,
		private ISystemTagManager $tagManager,
		private IJobList $jobList,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function index(): DataResponse {
		return new DataResponse(array_map([$this->jobService, 'toArray'], $this->mapper->findAll()));
	}

	public function tags(): DataResponse {
		$tags = [];
		foreach ($this->tagManager->getAllTags() as $tag) {
			$tags[] = [
				'id' => $tag->getId(),
				'name' => $tag->getName(),
				'userVisible' => $tag->isUserVisible(),
				'userAssignable' => $tag->isUserAssignable(),
			];
		}
		usort($tags, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
		return new DataResponse($tags);
	}

	public function create(): DataResponse {
		try {
			$job = $this->jobService->save(null, $this->request->getParams());
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->jobService->toArray($job), Http::STATUS_CREATED);
	}

	public function update(int $id): DataResponse {
		try {
			$job = $this->jobService->save($this->mapper->find($id), $this->request->getParams());
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($this->jobService->toArray($job));
	}

	public function destroy(int $id): DataResponse {
		try {
			$this->mapper->delete($this->mapper->find($id));
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		}
		$this->jobList->remove(RunBackup::class, ['jobId' => $id]);
		return new DataResponse([]);
	}

	/**
	 * Queue a run for the next cron execution; backups are too slow for an
	 * HTTP request.
	 */
	public function run(int $id): DataResponse {
		try {
			$this->mapper->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		}
		if (!$this->jobList->has(RunBackup::class, ['jobId' => $id])) {
			$this->jobList->add(RunBackup::class, ['jobId' => $id]);
		}
		return new DataResponse(['message' => 'Backup queued; it will start on the next background job run']);
	}

	public function test(int $id): DataResponse {
		try {
			$job = $this->mapper->find($id);
			$this->destinationFactory->create($job)->test();
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['message' => 'Connection OK']);
	}

	public function preview(int $id): DataResponse {
		try {
			$job = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		}
		$collected = $this->collector->collect($job);
		$files = 0;
		$bytes = 0;
		$sample = [];
		foreach ($collected['entries'] as $entry) {
			if (!$entry->isDirectory) {
				$files++;
				$bytes += (int)$entry->node->getSize();
			}
			if (count($sample) < 200) {
				$sample[] = $entry->archivePath . ($entry->isDirectory ? '/' : '');
			}
		}
		return new DataResponse([
			'files' => $files,
			'directories' => count($collected['entries']) - $files,
			'bytes' => $bytes,
			'sample' => $sample,
			'missing' => $collected['missing'],
			'unreachable' => count($collected['unreachable']),
		]);
	}
}
