#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;

$api = app(ZoomApiService::class);
$hub = app(MorpheusHubService::class);
$hub->bustCache();

$status = $api->connectionStatus();
echo 'Connection: '.json_encode($status).PHP_EOL;

$listed = $api->listExtensions(['limit' => 100]);
$exts = $listed['extensions'] ?? [];
echo 'API extensions count: '.count($exts).PHP_EOL;
foreach ($exts as $ext) {
    echo sprintf(
        "  num=%s id=%s dialer=%s status=%s user_id=%s cid=%s\n",
        $ext['extension_num'] ?? '-',
        $ext['id'] ?? '-',
        ($ext['is_dialer_agent'] ?? false) ? 'yes' : 'no',
        $ext['status'] ?? '-',
        $ext['user_id'] ?? '-',
        $ext['outbound_cid_num'] ?? ($ext['caller_id_num'] ?? '-')
    );
}

$billing = config('morpheus_billing_dids.extensions', []);
echo 'Billing DID map keys: '.implode(',', array_keys($billing)).PHP_EOL;
