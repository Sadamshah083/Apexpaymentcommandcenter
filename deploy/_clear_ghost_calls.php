<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workspace;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;
use App\Models\CommunicationCallLog;

$ev = app(MorpheusCallEventService::class);
$before = $ev->listLiveStates();
echo 'live_before='.count($before).PHP_EOL;
foreach ($before as $state) {
    echo '  uuid='.($state['uuid'] ?? '').' ext='.($state['from_extension'] ?? '').' dest='.($state['destination'] ?? '').' conn='.((($state['destination_connected'] ?? false) ? '1' : '0')).PHP_EOL;
    $ev->markCallEnded((string) ($state['uuid'] ?? ''), 'GHOST_CLEANUP', 0);
}

// Finish any stuck connected call logs for Jacob's extension.
CommunicationCallLog::query()
    ->where('from_extension', '1015')
    ->whereIn('status', ['initiated', 'ringing', 'active', 'connected', 'talking', 'bridging'])
    ->where('updated_at', '>=', now()->subHours(6))
    ->update(['status' => 'completed']);

$ended = $ev->endLiveCallsForLeg('1015', null, 'GHOST_CLEANUP');
echo 'ended_leg='.$ended.PHP_EOL;
echo 'live_after='.count($ev->listLiveStates()).PHP_EOL;

$ws = Workspace::query()->find(2);
$snap = app(CallMonitoringService::class)->snapshot($ws, light: true, probeConnected: false);
echo 'rows='.count($snap['rows'] ?? []).PHP_EOL;
echo 'ringing='.($snap['summary']['ringing'] ?? 0).PHP_EOL;
echo 'short='.($snap['summary']['in_call_short'] ?? 0).PHP_EOL;
echo 'long='.($snap['summary']['in_call_long'] ?? 0).PHP_EOL;
echo 'OK'.PHP_EOL;
