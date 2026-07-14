<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;
use App\Services\Communications\MorpheusHubService;
use App\Services\Integrations\ZoomApiService;

$hub = app(MorpheusHubService::class);
$events = app(MorpheusCallEventService::class);
$mon = app(CallMonitoringService::class);
$api = app(ZoomApiService::class);

$active = [];
try {
    $hub->bustCache();
    $active = $hub->activeCalls();
} catch (Throwable $e) {
    echo 'HUB_ERR: '.$e->getMessage().PHP_EOL;
}

echo 'ACTIVE_COUNT='.count($active).PHP_EOL;
echo 'ACTIVE='.substr(json_encode($active), 0, 12000).PHP_EOL;

$live = $events->listLiveStates();
echo 'LIVE_COUNT='.count($live).PHP_EOL;
echo 'LIVE='.substr(json_encode($live), 0, 12000).PHP_EOL;

$logs = CommunicationCallLog::query()
    ->where('created_at', '>=', now()->subMinutes(45))
    ->orderByDesc('id')
    ->limit(12)
    ->get(['id', 'status', 'to_phone', 'from_extension', 'morpheus_call_uuid', 'duration_sec', 'user_id', 'created_at', 'updated_at']);
echo 'LOGS='.json_encode($logs).PHP_EOL;

foreach ($logs as $log) {
    $uuid = (string) ($log->morpheus_call_uuid ?? '');
    if ($uuid === '') {
        continue;
    }
    try {
        $status = $api->hubLiveCallStatus($uuid, (string) $log->to_phone);
        echo 'STATUS uuid='.$uuid.' dest='.$log->to_phone.' => '.json_encode($status).PHP_EOL;
    } catch (Throwable $e) {
        echo 'STATUS_ERR uuid='.$uuid.' '.$e->getMessage().PHP_EOL;
    }
    $state = $events->getCallState($uuid);
    echo 'CACHE uuid='.$uuid.' => '.json_encode($state).PHP_EOL;
}

$snap = $mon->snapshot(null);
echo 'SUMMARY='.json_encode($snap['summary']).PHP_EOL;
foreach (($snap['rows'] ?? []) as $r) {
    echo 'ROW id='.($r['id'] ?? '')
        .' bucket='.($r['bucket'] ?? '')
        .' status='.($r['status'] ?? '')
        .' timer='.($r['timer_sec'] ?? 0)
        .' dest='.($r['destination'] ?? '')
        .' station='.($r['station'] ?? '')
        .' user='.($r['user'] ?? '')
        .' connected_at='.($r['connected_at'] ?? '')
        .PHP_EOL;
}
