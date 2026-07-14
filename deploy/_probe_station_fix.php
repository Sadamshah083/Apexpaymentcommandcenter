<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workspace;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;
use App\Models\CommunicationCallLog;
use App\Models\User;

$ws = Workspace::query()->find(2);
$jacob = User::query()->where('name', 'Jacob Khan')->first();
$ev = app(MorpheusCallEventService::class);
$u = 'station-probe-'.uniqid();

// Connected state WITHOUT from_extension (the bug case).
$ev->watchCall($u, '', '2092592594');
$ev->markDestinationConnected($u, '2092592594', 200, 'probe', now()->subSeconds(200)->toIso8601String());

// Local log with Jacob + 1015 keyed by uuid.
if ($jacob) {
    CommunicationCallLog::query()->create([
        'workspace_id' => $ws->id,
        'user_id' => $jacob->id,
        'morpheus_call_uuid' => $u,
        'from_extension' => '1015',
        'to_phone' => '2092592594',
        'direction' => 'outbound',
        'status' => 'connected',
        'duration_sec' => 200,
    ]);
}

$snap = app(CallMonitoringService::class)->snapshot($ws, light: true, probeConnected: false);
$row = collect($snap['rows'])->firstWhere('id', $u);
echo 'station='.($row['station'] ?? 'MISSING').PHP_EOL;
echo 'user='.($row['user'] ?? '').PHP_EOL;
echo 'role='.($row['role_label'] ?? '').PHP_EOL;

$ev->markCallEnded($u, 'CLEANUP', 0);
CommunicationCallLog::query()->where('morpheus_call_uuid', $u)->delete();
echo 'OK'.PHP_EOL;
