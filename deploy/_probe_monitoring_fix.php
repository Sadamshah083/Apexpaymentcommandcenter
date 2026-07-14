<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;

$events = app(MorpheusCallEventService::class);
$monitoring = app(CallMonitoringService::class);

foreach (['probe-dup-a', 'probe-dup-b', 'probe-dup-c', 'probe-conn-1'] as $u) {
    $events->markCallEnded($u, 'CLEANUP', 0);
}

$events->watchCall('probe-dup-a', '1015', '2092592594');
$events->watchCall('probe-dup-b', '1015', '12092592594');
$events->watchCall('probe-dup-c', '1015', '+12092592594');

$a = $events->getCallState('probe-dup-a');
$b = $events->getCallState('probe-dup-b');
$c = $events->getCallState('probe-dup-c');

echo 'live_a='.(($a['live'] ?? false) ? '1' : '0').PHP_EOL;
echo 'live_b='.(($b['live'] ?? false) ? '1' : '0').PHP_EOL;
echo 'live_c='.(($c['live'] ?? false) ? '1' : '0').PHP_EOL;

$snap = $monitoring->snapshot(null, light: true, probeConnected: false);
$legRows = array_values(array_filter(
    $snap['rows'] ?? [],
    static fn ($r) => str_contains((string) ($r['destination'] ?? ''), '2092592594')
        || str_contains((string) ($r['destination'] ?? ''), '92592594')
));
echo 'dup_rows='.count($legRows).PHP_EOL;
echo 'ringing_summary='.($snap['summary']['ringing'] ?? -1).PHP_EOL;

$events->watchCall('probe-conn-1', '1015', '2092592594');
$events->markDestinationConnected(
    'probe-conn-1',
    '2092592594',
    12,
    'probe',
    now()->subSeconds(30)->toIso8601String()
);
$snap2 = $monitoring->snapshot(null, light: true, probeConnected: false);
$row = collect($snap2['rows'])->firstWhere('id', 'probe-conn-1');
echo 'conn_bucket='.($row['bucket'] ?? 'missing').PHP_EOL;
echo 'conn_timer='.($row['timer_sec'] ?? -1).PHP_EOL;
echo 'short_count='.count($snap2['tables']['incall_short'] ?? []).PHP_EOL;

foreach (['probe-dup-a', 'probe-dup-b', 'probe-dup-c', 'probe-conn-1'] as $u) {
    $events->markCallEnded($u, 'CLEANUP', 0);
}

$nginx = @file_get_contents('/etc/nginx/sites-enabled/apexone') ?: '';
echo 'nginx_buffering='.(str_contains($nginx, 'fastcgi_buffering off') ? '1' : '0').PHP_EOL;
echo 'OK'.PHP_EOL;
