<?php

/**
 * Enable is_dialer_agent on all Morpheus extensions (required for click-to-call).
 * Usage: php scripts/enable_morpheus_dialer_agents.php
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

$extensions = $api->listExtensions(['limit' => 100])['extensions'] ?? [];
$updated = 0;

foreach ($extensions as $ext) {
    if ($ext['is_dialer_agent'] ?? false) {
        continue;
    }

    $id = $ext['id'] ?? null;
    $num = $ext['extension_num'] ?? '?';
    if (! $id) {
        continue;
    }

    $result = $api->updateExtension($id, ['is_dialer_agent' => true]);
    if (isset($result['error']) && ! isset($result['id'])) {
        echo "FAIL ext {$num}: {$result['error']}\n";
        continue;
    }

    echo "Enabled dialer agent on ext {$num}\n";
    $updated++;
}

echo "\nUpdated {$updated} extension(s).\n";
