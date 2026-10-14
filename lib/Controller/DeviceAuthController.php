<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Controller;

use OCA\TaggedBackup\AppInfo\Application;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Destination\DestinationException;
use OCA\TaggedBackup\Destination\DestinationFactory;
use OCA\TaggedBackup\Destination\GoogleDeviceFlow;
use OCA\TaggedBackup\Service\JobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\ISession;

/**
 * Device sign-in for the "Google Drive Device Sign-in" destination. The
 * browser starts the flow, shows the user code, and polls until Google
 * reports that the admin approved it. The device code stays in the session.
 * Admin only.
 */
class DeviceAuthController extends Controller {
	private const SESSION_KEY = 'tagged_backup_device';

	public function __construct(
		IRequest $request,
		private JobMapper $mapper,
		private JobService $jobService,
		private DestinationFactory $destinationFactory,
		private GoogleDeviceFlow $googleDeviceFlow,
		private ISession $session,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[UseSession]
	public function start(int $id): DataResponse {
		try {
			$job = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		}
		if ($job->getDestinationType() !== 'gdrive_device') {
			return new DataResponse(['message' => 'This job does not use device sign-in'], Http::STATUS_BAD_REQUEST);
		}
		[$clientId] = $this->googleDeviceFlow->client($this->jobService->getDestinationConfig($job));
		try {
			$flow = GoogleDeviceFlow::start($this->destinationFactory->http(), $clientId);
		} catch (DestinationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		$this->session->set(self::SESSION_KEY, [
			'jobId' => $id,
			'deviceCode' => $flow['device_code'],
			'expires' => time() + $flow['expires_in'],
		]);
		return new DataResponse([
			'userCode' => $flow['user_code'],
			'verificationUrl' => $flow['verification_url'],
			'expiresIn' => $flow['expires_in'],
			'interval' => $flow['interval'],
		]);
	}

	#[UseSession]
	public function poll(int $id): DataResponse {
		$pending = $this->session->get(self::SESSION_KEY);
		if (!is_array($pending) || $pending['jobId'] !== $id || $pending['expires'] < time()) {
			return new DataResponse(['message' => 'No sign-in in progress or it expired, please start again'], Http::STATUS_BAD_REQUEST);
		}
		try {
			$job = $this->mapper->find($id);
			[$clientId, $clientSecret] = $this->googleDeviceFlow->client($this->jobService->getDestinationConfig($job));
			$result = GoogleDeviceFlow::poll($this->destinationFactory->http(), $clientId, $clientSecret, $pending['deviceCode']);
		} catch (DoesNotExistException) {
			return new DataResponse(['message' => 'Job not found'], Http::STATUS_NOT_FOUND);
		} catch (DestinationException $e) {
			$this->session->remove(self::SESSION_KEY);
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
		if ($result['status'] === 'done') {
			$this->session->remove(self::SESSION_KEY);
			$this->jobService->storeRefreshToken($id, $result['refresh_token']);
		}
		return new DataResponse(['status' => $result['status']]);
	}
}
