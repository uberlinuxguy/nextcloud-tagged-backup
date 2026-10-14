<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Controller;

use OCA\TaggedBackup\AppInfo\Application;
use OCA\TaggedBackup\Db\JobMapper;
use OCA\TaggedBackup\Destination\DestinationFactory;
use OCA\TaggedBackup\Service\JobService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

/**
 * OAuth 2.0 authorization code flow used to connect a Google Drive or
 * OneDrive account to a backup job. Admin only.
 */
class OAuthController extends Controller {
	private const SESSION_KEY = 'tagged_backup_oauth';

	public function __construct(
		IRequest $request,
		private JobMapper $mapper,
		private JobService $jobService,
		private DestinationFactory $destinationFactory,
		private ISession $session,
		private IURLGenerator $urlGenerator,
		private ISecureRandom $random,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public static function redirectUri(IURLGenerator $urlGenerator): string {
		return $urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.oauth.callback');
	}

	#[NoCSRFRequired]
	#[UseSession]
	public function start(int $id): RedirectResponse {
		try {
			$job = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			return $this->backToSettings('error', 'Backup job not found');
		}
		$provider = $this->destinationFactory->oauthProvider($job);
		$config = $this->jobService->getDestinationConfig($job);
		$clientId = trim((string)($config['client_id'] ?? ''));
		if ($provider === null || $clientId === '') {
			return $this->backToSettings('error', 'Save the client ID and secret before connecting');
		}
		$state = $this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->session->set(self::SESSION_KEY, ['state' => $state, 'jobId' => $id]);
		return new RedirectResponse($provider->buildAuthorizeUrl($clientId, self::redirectUri($this->urlGenerator), $state));
	}

	#[NoCSRFRequired]
	#[UseSession]
	public function callback(string $state = '', string $code = '', string $error = '', string $error_description = ''): RedirectResponse {
		$pending = $this->session->get(self::SESSION_KEY);
		$this->session->remove(self::SESSION_KEY);
		if (!is_array($pending) || $state === '' || !hash_equals((string)$pending['state'], $state)) {
			return $this->backToSettings('error', 'Invalid or expired OAuth state, please try again');
		}
		if ($error !== '') {
			return $this->backToSettings('error', 'Authorization was refused: ' . ($error_description !== '' ? $error_description : $error));
		}
		try {
			$job = $this->mapper->find((int)$pending['jobId']);
			$provider = $this->destinationFactory->oauthProvider($job);
			$config = $this->jobService->getDestinationConfig($job);
			if ($provider === null) {
				throw new \RuntimeException('Job destination does not use OAuth');
			}
			$tokens = $provider->requestToken(
				$this->destinationFactory->http(),
				(string)($config['client_id'] ?? ''),
				(string)($config['client_secret'] ?? ''),
				[
					'grant_type' => 'authorization_code',
					'code' => $code,
					'redirect_uri' => self::redirectUri($this->urlGenerator),
				],
			);
			if (($tokens['refresh_token'] ?? '') === '') {
				throw new \RuntimeException('The provider did not issue a refresh token. Remove the app\'s access from your account and connect again.');
			}
			$this->jobService->storeRefreshToken($job->getId(), $tokens['refresh_token']);
		} catch (\Throwable $e) {
			return $this->backToSettings('error', $e->getMessage());
		}
		return $this->backToSettings('connected', $job->getName());
	}

	private function backToSettings(string $key, string $value): RedirectResponse {
		$url = $this->urlGenerator->linkToRoute('settings.AdminSettings.index', ['section' => Application::APP_ID]);
		return new RedirectResponse($url . '?' . http_build_query(['oauth_' . $key => $value]));
	}
}
