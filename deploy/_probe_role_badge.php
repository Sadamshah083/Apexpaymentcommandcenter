<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workspace;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Communications\MorpheusCallEventService;

$ws = Workspace::query()->find(2);
$hit = collect(app(CommunicationsAgentService::class)->listLocalExtensionDirectory($ws))
    ->first(fn ($a) => (string) ($a['morpheus_extension_num'] ?? '') === '1015');
echo 'hit_role='.($hit['role_label'] ?? 'MISSING').PHP_EOL;

$ev = app(MorpheusCallEventService::class);
$u = 'role-probe2-'.uniqid();
$ev->watchCall($u, '1015', '2092592594');
$snap = app(CallMonitoringService::class)->snapshot($ws, light: true, probeConnected: false);
$row = collect($snap['rows'])->firstWhere('id', $u);
echo 'row_role='.($row['role_label'] ?? 'MISSING').' user='.($row['user'] ?? '').PHP_EOL;
$ev->markCallEnded($u, 'CLEANUP', 0);
echo 'OK'.PHP_EOL;
