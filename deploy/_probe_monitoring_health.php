<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;
use App\Support\ReleaseSessionLock;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

$events = app(MorpheusCallEventService::class);
$monitoring = app(CallMonitoringService::class);
$out = [];

$out['routes'] = [
    'admin_stream' => Route::has('admin.communications.monitoring.stream'),
    'admin_live' => Route::has('admin.communications.monitoring.live'),
    'dest_connected' => Route::has('admin.communications.morpheus.calls.destination-connected'),
];

$live = $events->listLiveStates();
$out['live_count'] = count($live);
$out['live'] = array_map(fn ($s) => [
    'uuid' => $s['uuid'] ?? null,
    'live' => $s['live'] ?? null,
    'answered' => $s['destination_answered'] ?? null,
    'connected_at' => $s['connected_at'] ?? null,
    'destination' => $s['destination'] ?? null,
    'ext' => $s['from_extension'] ?? null,
    'source' => $s['source'] ?? null,
], $live);

$logs = CommunicationCallLog::query()
    ->where('created_at', '>=', now()->subMinutes(30))
    ->orderByDesc('id')
    ->limit(12)
    ->get(['id', 'status', 'to_phone', 'from_extension', 'morpheus_call_uuid', 'user_id', 'created_at', 'updated_at']);
$out['recent_logs'] = $logs;

$light = $monitoring->snapshot(null, light: true);
$out['light_summary'] = $light['summary'] ?? [];
$out['light_rows'] = array_map(fn ($r) => [
    'id' => $r['id'] ?? null,
    'bucket' => $r['bucket'] ?? null,
    'status' => $r['status'] ?? null,
    'timer' => $r['timer_sec'] ?? null,
    'dest' => $r['destination'] ?? null,
    'station' => $r['station'] ?? null,
    'user' => $r['user'] ?? null,
], $light['rows'] ?? []);

// Dedup check: same dest+station appearing multiple times while ringing
$keys = [];
foreach ($out['light_rows'] as $r) {
    $k = ($r['station'] ?? '').'|'.preg_replace('/\D/', '', (string) ($r['dest'] ?? ''));
    $keys[$k] = ($keys[$k] ?? 0) + 1;
}
$out['dup_keys'] = array_filter($keys, fn ($c) => $c > 1);

// Simulate mark connected + light snapshot
$uuid = 'probe-stream-'.uniqid();
$events->watchCall($uuid, '1015', '2092592594');
$events->markDestinationConnected($uuid, '2092592594', 1, 'probe', now()->subSeconds(12)->toIso8601String());
$after = $monitoring->snapshot(null, light: true);
$row = collect($after['rows'] ?? [])->firstWhere('id', $uuid);
$out['mark_then_short'] = [
    'ok' => ($row['bucket'] ?? null) === 'incall_short',
    'row' => $row,
];
$events->markCallEnded($uuid, 'PROBE', 0);

// Session release helper exists
$out['release_helper'] = class_exists(\App\Support\ReleaseSessionLock::class);

// JS bundle has navOnly / stream
$jsHit = false;
foreach (glob(base_path('public/build/assets/call-monitoring-*.js')) ?: [] as $f) {
    $c = file_get_contents($f) ?: '';
    if (str_contains($c, 'navOnly') || str_contains($c, '30000')) {
        $jsHit = true;
    }
}
$out['js_nav_only'] = $jsHit;

echo json_encode($out, JSON_PRETTY_PRINT).PHP_EOL;
