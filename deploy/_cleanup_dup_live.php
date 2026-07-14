<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;

$events = app(MorpheusCallEventService::class);
$monitoring = app(CallMonitoringService::class);

$live = $events->listLiveStates();
echo 'live_before='.count($live).PHP_EOL;

// Collapse duplicate live legs: keep newest uuid per ext|dest.
$best = [];
foreach ($live as $state) {
    $ext = preg_replace('/\D/', '', (string) ($state['from_extension'] ?? '')) ?? '';
    $dest = preg_replace('/\D/', '', (string) ($state['destination'] ?? '')) ?? '';
    if (strlen($dest) === 11 && str_starts_with($dest, '1')) {
        $dest = substr($dest, 1);
    }
    if (strlen($dest) > 10) {
        $dest = substr($dest, -10);
    }
    $key = $ext.'|'.$dest;
    if ($key === '|') {
        continue;
    }
    $uuid = (string) ($state['uuid'] ?? '');
    $updated = (string) ($state['updated_at'] ?? '');
    if (! isset($best[$key]) || strcmp($updated, (string) ($best[$key]['updated_at'] ?? '')) > 0) {
        $best[$key] = $state + ['_uuid' => $uuid];
    }
}

$keep = array_map(static fn ($s) => (string) ($s['_uuid'] ?? $s['uuid'] ?? ''), $best);
$ended = 0;
foreach ($live as $state) {
    $uuid = (string) ($state['uuid'] ?? '');
    if ($uuid === '' || in_array($uuid, $keep, true)) {
        continue;
    }
    $events->markCallEnded($uuid, 'DEDUPE_CLEANUP', isset($state['billsec']) ? (int) $state['billsec'] : null);
    $ended++;
}

$snap = $monitoring->snapshot(null, light: true, probeConnected: false);
echo 'ended_dupes='.$ended.PHP_EOL;
echo 'live_after='.count($events->listLiveStates()).PHP_EOL;
echo 'rows='.count($snap['rows'] ?? []).PHP_EOL;
echo 'ringing='.($snap['summary']['ringing'] ?? 0).PHP_EOL;
echo 'short='.($snap['summary']['in_call_short'] ?? 0).PHP_EOL;
echo 'long='.($snap['summary']['in_call_long'] ?? 0).PHP_EOL;
echo 'OK'.PHP_EOL;
