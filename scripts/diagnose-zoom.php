<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(\App\Services\Integrations\ZoomApiService::class);
$data = app(\App\Services\Communications\CommunicationsDataService::class);
$filters = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()];

echo "=== Zoom diagnostics ===\n";
echo 'connected: '.($zoom->connectionStatus()['connected'] ? 'yes' : 'no')."\n\n";

foreach ([
    'listCallLogs (raw)' => fn () => $zoom->listCallLogs($filters),
    'data.callLogs' => fn () => $data->callLogs($filters, 1),
    'listRecordings (raw)' => fn () => $zoom->listRecordings($filters),
    'data.recordings' => fn () => $data->recordings($filters),
] as $label => $fn) {
    try {
        $r = $fn();
        $count = count($r['call_logs'] ?? $r['logs'] ?? $r['recordings'] ?? []);
        echo "$label: count=$count\n";
        if ($r['warning'] ?? null) {
            echo "  warning: {$r['warning']}\n";
        }
        if (! empty($r['warnings'])) {
            echo '  warnings: '.json_encode($r['warnings'])."\n";
        }
    } catch (Throwable $e) {
        echo "$label: ERROR {$e->getMessage()}\n";
    }
}

// Direct endpoint probes
$r = new ReflectionClass($zoom);
$req = $r->getMethod('request');
$req->setAccessible(true);
$q = array_merge($filters, ['page_size' => 5]);

foreach ([
    '/phone/call_history',
    '/phone/call_logs',
    '/phone/recordings',
    '/accounts/'.config('integrations.zoom.account_id').'/recordings',
] as $path) {
    try {
        $resp = $req->invoke($zoom, 'get', $path, $q);
        $keys = array_keys($resp);
        echo "\n$path OK keys=".implode(',', $keys)."\n";
        foreach (['call_history', 'call_logs', 'recordings', 'meetings'] as $k) {
            if (isset($resp[$k])) {
                echo "  $k count=".count($resp[$k])."\n";
            }
        }
    } catch (Throwable $e) {
        echo "\n$path FAIL: ".$e->getMessage()."\n";
    }
}
