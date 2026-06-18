<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$zoom = app(\App\Services\Integrations\ZoomApiService::class);
$data = app(\App\Services\Communications\CommunicationsDataService::class);

$zoom->clearAccessTokenCache();
\Illuminate\Support\Facades\Cache::forget('zoom.connection.diagnostics');
$data->bustCache();

$filters = [
    'from' => now()->subDays(90)->toDateString(),
    'to' => now()->toDateString(),
];

echo "Account: ".config('integrations.zoom.account_id')."\n";
echo "Client:  ".config('integrations.zoom.client_id')."\n\n";

echo "=== connectionDiagnostics ===\n";
echo json_encode($zoom->connectionDiagnostics(), JSON_PRETTY_PRINT)."\n\n";

$users = $zoom->listUsers(['per_page' => 3]);
echo 'Users: '.count($users['users'])."\n";
$firstUserId = $users['users'][0]['id'] ?? null;
if ($firstUserId) {
    echo "First user: {$users['users'][0]['email']} ({$firstUserId})\n\n";
}

$checks = [
    'callLogs' => fn () => $zoom->listCallLogs($filters),
    'recordings' => fn () => $zoom->listRecordings($filters),
    'voiceMails' => fn () => $zoom->listVoiceMails($filters),
    'smsSessions' => fn () => $zoom->listSmsSessions($filters),
    'phoneUsers' => fn () => $zoom->listPhoneUsers($filters),
];

foreach ($checks as $name => $fn) {
    try {
        $r = $fn();
        $count = count(
            $r['call_logs']
            ?? $r['recordings']
            ?? $r['voice_mails']
            ?? $r['sessions']
            ?? $r['users']
            ?? []
        );
        echo "=== {$name}: count={$count} ===\n";
        if ($r['warning'] ?? null) {
            echo "warning: {$r['warning']}\n";
        }
        if (! empty($r['warnings'])) {
            echo 'warnings: '.json_encode($r['warnings'], JSON_UNESCAPED_SLASHES)."\n";
        }
        echo "\n";
    } catch (Throwable $e) {
        echo "=== {$name}: EXCEPTION ===\n{$e->getMessage()}\n\n";
    }
}

echo "=== Data layer (cached path) ===\n";
foreach ([
    'data.callLogs' => fn () => $data->callLogs($filters, 1),
    'data.recordings' => fn () => $data->recordings($filters),
] as $label => $fn) {
    $r = $fn();
    $count = count($r['logs'] ?? $r['recordings'] ?? []);
    echo "{$label}: count={$count}\n";
    if ($r['warning'] ?? null) {
        echo "  warning: {$r['warning']}\n";
    }
    if (! empty($r['warnings'])) {
        echo '  warnings: '.json_encode($r['warnings'])."\n";
    }
}

$r = new ReflectionClass($zoom);
$req = $r->getMethod('request');
$req->setAccessible(true);
$q = array_merge($filters, ['page_size' => 5]);

echo "\n=== Raw endpoints ===\n";
$paths = [
    '/phone/call_history',
    '/phone/recordings',
    '/phone/voice_mails',
    '/phone/sms/sessions',
    '/phone/users',
];
if ($firstUserId) {
    $paths[] = "/users/{$firstUserId}/recordings";
}
$paths[] = '/accounts/'.config('integrations.zoom.account_id').'/recordings';

foreach ($paths as $path) {
    try {
        $resp = $req->invoke($zoom, 'get', $path, $q);
        foreach (['call_history', 'call_logs', 'recordings', 'meetings', 'voice_mails', 'sms_sessions', 'users'] as $k) {
            if (isset($resp[$k])) {
                echo "{$path} → {$k}: ".count($resp[$k])."\n";
            }
        }
        if (! array_intersect_key(array_flip(['call_history', 'call_logs', 'recordings', 'meetings', 'voice_mails', 'sms_sessions', 'users']), $resp)) {
            echo "{$path} → OK keys: ".implode(', ', array_keys($resp))."\n";
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (preg_match('/"code":(\d+)/', $msg, $m)) {
            echo "{$path} → FAIL code {$m[1]}: ".substr($msg, 0, 280)."\n";
        } else {
            echo "{$path} → FAIL: ".substr($msg, 0, 280)."\n";
        }
    }
}
