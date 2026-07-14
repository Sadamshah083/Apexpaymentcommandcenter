#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Integrations\ZoomApiService;

$api = app(ZoomApiService::class);
$id = '7a11400d-9224-404e-9910-801690d53fef';
$did = '14048501729';

$result = $api->updateExtension($id, [
    'is_dialer_agent' => true,
    'status' => 'active',
    'override_campaign_cid' => true,
    'caller_id_num' => $did,
    'outbound_cid_num' => $did,
    'caller_id_name' => 'tonnynewman',
    'outbound_cid_name' => 'tonnynewman',
]);

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
