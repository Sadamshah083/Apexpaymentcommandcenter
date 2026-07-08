<?php

/**
 * Clear zombie calls + optional SIP kick so an extension can ring again.
 * Usage: php scripts/clear_extension_for_dial.php 1020 [--kick]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Integrations\ZoomApiService;

$extension = preg_replace('/\D/', '', $argv[1] ?? '1020') ?: '1020';
$kick = in_array('--kick', $argv, true);

$api = app(ZoomApiService::class);

echo "Clearing extension {$extension} (kick=".($kick ? 'yes' : 'no').")\n";
echo 'Active before: '.count($api->listCalls()['calls'] ?? [])."\n";

$result = $api->clearExtensionForOutboundDial($extension, $kick);

echo 'Released UUIDs: '.json_encode($result['released'] ?? [])."\n";
echo 'SIP kicked: '.(($result['kicked'] ?? false) ? 'yes' : 'no')."\n";
echo 'Active after: '.count($api->listCalls()['calls'] ?? [])."\n";
