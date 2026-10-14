<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Settings;

use OCA\TaggedBackup\AppInfo\Application;
use OCA\TaggedBackup\Controller\OAuthController;
use OCA\TaggedBackup\Destination\GoogleDeviceFlow;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private IURLGenerator $urlGenerator,
		private GoogleDeviceFlow $googleDeviceFlow,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'redirectUri' => OAuthController::redirectUri($this->urlGenerator),
			'hasBzip2' => in_array('bzip2.*', stream_get_filters(), true),
			'hasGoogleDeviceClient' => $this->googleDeviceFlow->hasDefaultClient(),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
