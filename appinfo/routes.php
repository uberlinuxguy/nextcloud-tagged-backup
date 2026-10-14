<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'job#index', 'url' => '/api/jobs', 'verb' => 'GET'],
		['name' => 'job#create', 'url' => '/api/jobs', 'verb' => 'POST'],
		['name' => 'job#update', 'url' => '/api/jobs/{id}', 'verb' => 'PUT'],
		['name' => 'job#destroy', 'url' => '/api/jobs/{id}', 'verb' => 'DELETE'],
		['name' => 'job#run', 'url' => '/api/jobs/{id}/run', 'verb' => 'POST'],
		['name' => 'job#test', 'url' => '/api/jobs/{id}/test', 'verb' => 'POST'],
		['name' => 'job#preview', 'url' => '/api/jobs/{id}/preview', 'verb' => 'GET'],
		['name' => 'job#tags', 'url' => '/api/tags', 'verb' => 'GET'],
		['name' => 'device_auth#start', 'url' => '/api/jobs/{id}/device/start', 'verb' => 'POST'],
		['name' => 'device_auth#poll', 'url' => '/api/jobs/{id}/device/poll', 'verb' => 'POST'],
		['name' => 'oauth#start', 'url' => '/oauth/start/{id}', 'verb' => 'GET'],
		['name' => 'oauth#callback', 'url' => '/oauth/callback', 'verb' => 'GET'],
	],
];
