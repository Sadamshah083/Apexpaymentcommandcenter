<?php

/**
 * Ensure Morpheus extensions are dialer-ready: password, is_dialer_agent, outbound CID.
 * Usage: php scripts/provision_morpheus_extensions.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Integrations\ZoomApiService;

$api = app(ZoomApiService::class);
if (! $api->isConfigured()) {
    fwrite(STDERR, "Morpheus API not configured.\n");
    exit(1);
}

$password = (string) env('MORPHEUS_EXTENSION_PASSWORD', 'apexone_3344');
$extensions = $api->listExtensions(['limit' => 100])['extensions'] ?? [];
$updated = 0;

foreach ($extensions as $ext) {
    $id = $ext['id'] ?? null;
    $num = $ext['extension_num'] ?? '?';
    if (! $id) {
        continue;
    }

    $payload = array_filter([
        'password' => $password,
        'is_dialer_agent' => true,
        'outbound_cid_num' => $ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? null,
        'caller_id_num' => $ext['caller_id_num'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');

    $result = $api->updateExtension($id, $payload);
    if (isset($result['error']) && ! isset($result['id'])) {
        echo "FAIL ext {$num}: {$result['error']}\n";
        continue;
    }

    echo "Provisioned ext {$num}\n";
    $updated++;
}

echo "\nProvisioned {$updated} extension(s). SIP password: {$password}\n";
