<?php

declare(strict_types=1);

/**
 * Exercises the Google Drive and OneDrive upload protocols (resumable
 * uploads, resume after failures, pagination, token rotation) against
 * in-process fakes of both APIs, through the real Nextcloud HTTP client.
 *
 * usage: php tests/destination_fake_test.php /path/to/nextcloud
 */

require rtrim($argv[1] ?? '', '/') . '/lib/base.php';

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use OCA\TaggedBackup\Destination\GoogleDeviceFlow;
use OCA\TaggedBackup\Destination\GoogleDriveDestination;
use OCA\TaggedBackup\Destination\Http;
use OCA\TaggedBackup\Destination\OneDriveDestination;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

$failures = 0;
function check(bool $ok, string $what): void {
	global $failures;
	echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
	if (!$ok) {
		$failures++;
	}
}

/** Routes every request to a PHP callable instead of the network. */
class FakeClientService implements IClientService {
	/** @var callable(RequestInterface): Response */
	public $handler;
	public array $log = [];

	public function newClient(?callable $handler = null): IClient {
		$real = \OCP\Server::get(\OC\Http\Client\ClientService::class);
		return $real->newClient(function (RequestInterface $request, array $options) {
			$this->log[] = $request->getMethod() . ' ' . $request->getUri();
			return Create::promiseFor(($this->handler)($request));
		});
	}
}

function query(RequestInterface $r): array {
	parse_str($r->getUri()->getQuery(), $q);
	return $q;
}

$file = tempnam(sys_get_temp_dir(), 'tbtest');
file_put_contents($file, random_bytes(37 * 1024 * 1024 + 12345));
$size = filesize($file);
$expected = file_get_contents($file);
$services = new FakeClientService();
$http = new Http($services, new NullLogger());

// ---------------------------------------------------------------- Google ---
$g = ['folders' => [], 'files' => [], 'upload' => '', 'putCount' => 0, 'auth' => []];
$services->handler = function (RequestInterface $r) use (&$g, $size) {
	$uri = (string)$r->getUri();
	$path = $r->getUri()->getPath();
	$g['auth'][] = $r->getHeaderLine('Authorization');
	if ($uri === 'https://oauth2.googleapis.com/token') {
		parse_str((string)$r->getBody(), $form);
		return $form['refresh_token'] === 'g-refresh'
			? new Response(200, [], json_encode(['access_token' => 'g-access', 'expires_in' => 3600]))
			: new Response(400, [], json_encode(['error' => 'invalid_grant']));
	}
	if ($r->getHeaderLine('Authorization') !== 'Bearer g-access') {
		return new Response(401);
	}
	if ($path === '/drive/v3/files' && $r->getMethod() === 'GET') {
		$q = query($r)['q'];
		if (str_contains($q, "mimeType = 'application/vnd.google-apps.folder'")) {
			return new Response(200, [], json_encode(['files' => array_values($g['folders'])]));
		}
		// Paginate children, two per page.
		$page = (int)(query($r)['pageToken'] ?? 0);
		$all = array_values($g['files']);
		$body = ['files' => array_slice($all, $page, 2)];
		if ($page + 2 < count($all)) {
			$body['nextPageToken'] = (string)($page + 2);
		}
		return new Response(200, [], json_encode($body));
	}
	if ($path === '/drive/v3/files' && $r->getMethod() === 'POST') {
		$meta = json_decode((string)$r->getBody(), true);
		$g['folders']['F1'] = ['id' => 'F1', 'name' => $meta['name']];
		return new Response(200, [], json_encode(['id' => 'F1']));
	}
	if ($path === '/upload/drive/v3/files' && $r->getMethod() === 'POST') {
		$meta = json_decode((string)$r->getBody(), true);
		$g['meta'] = $meta;
		$g['declared'] = $r->getHeaderLine('X-Upload-Content-Length');
		return new Response(200, ['Location' => 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=U1']);
	}
	if ($path === '/upload/drive/v3/files' && $r->getMethod() === 'PUT') {
		$g['putCount']++;
		$range = $r->getHeaderLine('Content-Range');
		if ($range === "bytes */$size") {
			return new Response(308, strlen($g['upload']) ? ['Range' => 'bytes=0-' . (strlen($g['upload']) - 1)] : []);
		}
		preg_match('#bytes (\d+)-(\d+)/(\d+)#', $range, $m);
		if ((int)$m[1] !== strlen($g['upload'])) {
			return new Response(400, [], 'bad offset');
		}
		$body = (string)$r->getBody();
		if ($g['putCount'] === 2) {
			return new Response(503, [], 'transient'); // must be resumed via status probe
		}
		if ($g['putCount'] === 4) {
			$body = substr($body, 0, 1000); // server only persisted part of the chunk
			$g['upload'] .= $body;
			return new Response(308, ['Range' => 'bytes=0-' . (strlen($g['upload']) - 1)]);
		}
		$g['upload'] .= $body;
		if (strlen($g['upload']) === $size) {
			$g['files']['B' . count($g['files'])] = ['id' => 'B' . count($g['files']), 'name' => $g['meta']['name']];
			return new Response(200, [], json_encode(['id' => 'X']));
		}
		return new Response(308, ['Range' => 'bytes=0-' . (strlen($g['upload']) - 1)]);
	}
	if (str_starts_with($path, '/drive/v3/files/') && $r->getMethod() === 'DELETE') {
		unset($g['files'][substr($path, 16)]);
		return new Response(204);
	}
	return new Response(500, [], 'unexpected ' . $r->getMethod() . ' ' . $uri);
};

$rotated = [];
$gd = new GoogleDriveDestination($http, 'cid', 'secret', 'g-refresh', function ($t) use (&$rotated) {
	$rotated[] = $t;
}, 'My Backups');
$gd->test();
check(($g['folders']['F1']['name'] ?? '') === 'My Backups', 'Google: folder created on first use');
$gd->upload($file, 'job-1-20260101-000000Z.tar.gz', $size);
check($g['upload'] === $expected, 'Google: uploaded bytes identical after a 503 and a partial chunk');
check($g['meta']['parents'] === ['F1'] && $g['declared'] === (string)$size, 'Google: metadata and declared length');
for ($i = 0; $i < 4; $i++) {
	$g['files']['E' . $i] = ['id' => 'E' . $i, 'name' => "extra$i"];
}
$listed = $gd->listFiles();
check(count($listed) === 5, 'Google: pagination lists all 5 files (got ' . count($listed) . ')');
$gd->delete($listed[0]);
check(count($g['files']) === 4, 'Google: delete');
$bad = new GoogleDriveDestination($http, 'cid', 'secret', 'revoked', fn () => null, '');
try {
	$bad->test();
	check(false, 'Google: revoked refresh token raises');
} catch (\Throwable $e) {
	check(str_contains($e->getMessage(), 'invalid_grant'), 'Google: revoked refresh token raises: ' . $e->getMessage());
}

// -------------------------------------------------------------- OneDrive ---
$o = ['items' => [], 'upload' => '', 'putCount' => 0, 'refresh' => 'o-refresh-1', 'uploadAuth' => []];
$services->handler = function (RequestInterface $r) use (&$o, $size) {
	$uri = (string)$r->getUri();
	$path = rawurldecode($r->getUri()->getPath());
	if ($uri === 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token') {
		parse_str((string)$r->getBody(), $form);
		if ($form['refresh_token'] !== $o['refresh'] || !str_contains($form['scope'], 'Files.ReadWrite')) {
			return new Response(400, [], json_encode(['error' => 'invalid_grant', 'error_description' => 'bad token']));
		}
		$o['refresh'] = 'o-refresh-2'; // Microsoft rotates refresh tokens
		return new Response(200, [], json_encode(['access_token' => 'o-access', 'expires_in' => 3600, 'refresh_token' => 'o-refresh-2']));
	}
	if ($r->getUri()->getHost() === 'api.onedrive.com') {
		$o['uploadAuth'][] = $r->getHeaderLine('Authorization');
		$next = strlen($o['upload']);
		if ($r->getMethod() === 'GET') {
			return new Response(200, [], json_encode(['nextExpectedRanges' => ["$next-"]]));
		}
		$o['putCount']++;
		preg_match('#bytes (\d+)-(\d+)/(\d+)#', $r->getHeaderLine('Content-Range'), $m);
		if ((int)$m[1] !== $next) {
			return new Response(416, [], json_encode(['error' => ['message' => 'range']]));
		}
		if (($m[2] - $m[1] + 1) % 327680 !== 0 && (int)$m[2] !== $size - 1) {
			return new Response(400, [], json_encode(['error' => ['message' => 'chunk not multiple of 320 KiB']]));
		}
		if ($o['putCount'] === 2) {
			$o['upload'] .= (string)$r->getBody(); // stored, but the response got lost
			return new Response(500, [], 'oops');
		}
		$o['upload'] .= (string)$r->getBody();
		if (strlen($o['upload']) === $size) {
			$o['items']['backups/' . $o['uploadName']] = true;
			return new Response(201, [], json_encode(['id' => 'done']));
		}
		return new Response(202, [], json_encode(['nextExpectedRanges' => [strlen($o['upload']) . '-']]));
	}
	if ($r->getHeaderLine('Authorization') !== 'Bearer o-access') {
		return new Response(401);
	}
	if (preg_match('#^/v1.0/me/drive/root:/([^:]+):?$#', $path, $m) && $r->getMethod() === 'GET') {
		return isset($o['items'][$m[1]]) ? new Response(200, [], '{}') : new Response(404, [], '{}');
	}
	if (preg_match('#^/v1.0/me/drive/root(?::/(.+):)?/children$#', $path, $m) && $r->getMethod() === 'POST') {
		$name = json_decode((string)$r->getBody(), true)['name'];
		$o['items'][(isset($m[1]) && $m[1] !== '' ? $m[1] . '/' : '') . $name] = true;
		return new Response(201, [], '{}');
	}
	if (preg_match('#^/v1.0/me/drive/root:/(.+):/createUploadSession$#', $path, $m)) {
		$o['uploadName'] = basename($m[1]);
		return new Response(200, [], json_encode(['uploadUrl' => 'https://api.onedrive.com/session/1']));
	}
	if (preg_match('#^/v1.0/me/drive/root:/(.+):/children$#', $path, $m)) {
		$files = [];
		foreach (array_keys($o['items']) as $key) {
			if (dirname($key) === $m[1]) {
				$files[] = ['id' => 'id:' . $key, 'name' => basename($key)];
			}
		}
		$skip = (int)(query($r)['skip'] ?? 0);
		$body = ['value' => array_slice($files, $skip, 2)];
		if ($skip + 2 < count($files)) {
			$body['@odata.nextLink'] = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . $m[1] . ':/children?skip=' . ($skip + 2);
		}
		return new Response(200, [], json_encode($body));
	}
	if (str_starts_with($path, '/v1.0/me/drive/items/') && $r->getMethod() === 'DELETE') {
		unset($o['items'][substr(substr($path, strlen('/v1.0/me/drive/items/')), 3)]);
		return new Response(204);
	}
	return new Response(500, [], 'unexpected ' . $r->getMethod() . ' ' . $uri);
};

$rotated = [];
$od = new OneDriveDestination($http, 'consumers', 'cid', 'secret', 'o-refresh-1', function ($t) use (&$rotated) {
	$rotated[] = $t;
}, '/backups/');
$od->test();
check(isset($o['items']['backups']), 'OneDrive: folder created');
check($rotated === ['o-refresh-2'], 'OneDrive: rotated refresh token persisted');
$od->upload($file, 'job-1-20260101-000000Z.tar', $size);
check($o['upload'] === $expected, 'OneDrive: uploaded bytes identical after a lost response');
check(array_filter($o['uploadAuth']) === [], 'OneDrive: no bearer token sent to the pre-authenticated upload URL');
foreach (['a', 'b', 'c', 'd'] as $n) {
	$o['items']["backups/$n"] = true;
}
$listed = $od->listFiles();
check(count($listed) === 5, 'OneDrive: pagination lists all 5 files (got ' . count($listed) . ')');
$od->delete($listed[0]);
check(count($od->listFiles()) === 4, 'OneDrive: delete');

// ------------------------------------------------- Google device sign-in ---
$d = ['polls' => 0, 'script' => []];
$services->handler = function (RequestInterface $r) use (&$d) {
	parse_str((string)$r->getBody(), $form);
	if ((string)$r->getUri() === GoogleDeviceFlow::DEVICE_CODE_URL) {
		$d['startForm'] = $form;
		return new Response(200, [], json_encode([
			'device_code' => 'DEV-1', 'user_code' => 'ABCD-EFGH', 'verification_url' => 'https://www.google.com/device',
			'expires_in' => 1800, 'interval' => 5,
		]));
	}
	if ((string)$r->getUri() === 'https://oauth2.googleapis.com/token' && $form['grant_type'] === GoogleDeviceFlow::GRANT_TYPE) {
		$d['pollForm'] = $form;
		[$status, $body] = array_shift($d['script']);
		return new Response($status, [], json_encode($body));
	}
	return new Response(500, [], 'unexpected');
};
$flow = GoogleDeviceFlow::start($http, 'dev-client');
check($flow['user_code'] === 'ABCD-EFGH' && $flow['interval'] === 5, 'Device: start returns user code and interval');
check($d['startForm'] === ['client_id' => 'dev-client', 'scope' => 'https://www.googleapis.com/auth/drive.file'], 'Device: requests only the drive.file scope');
$d['script'] = [
	[428, ['error' => 'authorization_pending']],
	[403, ['error' => 'slow_down']],
	[200, ['access_token' => 'a', 'refresh_token' => 'dev-refresh', 'expires_in' => 3599]],
];
$statuses = [];
for ($i = 0; $i < 3; $i++) {
	$statuses[] = GoogleDeviceFlow::poll($http, 'dev-client', 'dev-secret', $flow['device_code']);
}
check(array_column($statuses, 'status') === ['pending', 'slow_down', 'done'] && $statuses[2]['refresh_token'] === 'dev-refresh', 'Device: pending → slow_down → done with refresh token');
check($d['pollForm']['device_code'] === 'DEV-1' && $d['pollForm']['client_secret'] === 'dev-secret', 'Device: poll sends device code and client secret');
foreach (['access_denied' => 'declined', 'expired_token' => 'expired', 'invalid_client' => 'invalid_client'] as $error => $expect) {
	$d['script'] = [[400, ['error' => $error]]];
	try {
		GoogleDeviceFlow::poll($http, 'dev-client', 'dev-secret', 'DEV-1');
		check(false, "Device: $error raises");
	} catch (\OCA\TaggedBackup\Destination\DestinationException $e) {
		check(str_contains($e->getMessage(), $expect), "Device: $error raises: " . $e->getMessage());
	}
}

$appConfig = \OCP\Server::get(\OCP\IAppConfig::class);
$deviceFlow = new GoogleDeviceFlow($appConfig);
$appConfig->deleteKey('tagged_backup', 'gdrive_device_client_id');
check($deviceFlow->client([]) === ['', ''] && !$deviceFlow->hasDefaultClient(), 'Device: no default client unless configured');
$appConfig->setValueString('tagged_backup', 'gdrive_device_client_id', 'instance-id');
$appConfig->setValueString('tagged_backup', 'gdrive_device_client_secret', 'instance-secret', sensitive: true);
check($deviceFlow->client([]) === ['instance-id', 'instance-secret'], 'Device: instance default client used when job has none');
check($deviceFlow->client(['client_id' => 'job-id', 'client_secret' => 'job-secret']) === ['job-id', 'job-secret'], 'Device: job client overrides instance default');
$appConfig->deleteKey('tagged_backup', 'gdrive_device_client_id');
$appConfig->deleteKey('tagged_backup', 'gdrive_device_client_secret');

unlink($file);
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
