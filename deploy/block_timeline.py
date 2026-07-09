#!/usr/bin/env python3
"""Timeline: when ghost calls / USER_BUSY blocking started."""
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$api = app(App\Services\Integrations\ZoomApiService::class);

echo "GHOST ACTIVE CALLS (Morpheus /calls):\n";
$live = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://{$host}/api/v1/call-control/calls");
foreach ($live->json('calls') ?? [] as $c) {
    echo "  uuid={$c['uuid']} started={$c['started_at']} phone={$c['phone_number']} status={$c['status']}\n";
}

echo "\nCDR ext 1020 today (newest first, 25 rows):\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://{$host}/api/v1/call-control/cdr", ['limit' => 50]);
$rows = [];
foreach ($r->json('cdr') ?? [] as $row) {
    if (($row['agent_extension'] ?? '') === '1020' || str_contains($row['destination_number'] ?? '', '2722001232')) {
        $rows[] = $row;
    }
}
usort($rows, fn($a,$b) => strcmp($b['start_time'] ?? '', $a['start_time'] ?? ''));
foreach (array_slice($rows, 0, 25) as $row) {
    $t = $row['start_time'] ?? '?';
    $cause = $row['hangup_cause'] ?? '?';
    $bill = $row['billsec'] ?? 0;
    $dest = $row['destination_number'] ?? '?';
    $ext = $row['agent_extension'] ?? '-';
    echo "  {$t} ext={$ext} dest={$dest} billsec={$bill} cause={$cause}\n";
}

echo "\nDB call logs ext 1020 (first/last USER_BUSY vs success):\n";
use App\Models\CommunicationCallLog;
$logs = CommunicationCallLog::where('from_extension', '1020')
    ->where('to_phone', 'like', '%12722001232%')
    ->orderBy('created_at')
    ->get(['id','created_at','morpheus_call_uuid']);
$firstBusy = null; $firstBusyAt = null;
$lastOk = null; $lastOkAt = null;
foreach ($logs as $log) {
    if (!$log->morpheus_call_uuid) continue;
    $cdr = $api->getCall($log->morpheus_call_uuid);
    if (!$cdr) continue;
    $cause = $cdr['hangup_cause'] ?? '';
    $bill = (int)($cdr['billsec'] ?? 0);
  if ($cause === 'USER_BUSY' && $firstBusyAt === null) {
    $firstBusyAt = $log->created_at;
    $firstBusy = $log->morpheus_call_uuid;
  }
  if ($bill >= 3 && in_array($cause, ['NORMAL_CLEARING', ''])) {
    $lastOkAt = $log->created_at;
    $lastOk = $log->morpheus_call_uuid;
  }
}
echo "  First USER_BUSY in logs: ".($firstBusyAt ?: 'n/a')." uuid=".($firstBusy ?: 'n/a')."\n";
echo "  Last successful (billsec>=3): ".($lastOkAt ?: 'n/a')." uuid=".($lastOk ?: 'n/a')."\n";
echo "  Total 1020 dials to test# in DB: ".$logs->count()."\n";
"""

ssh = connect()
tmp = "/tmp/block-timeline.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False)
print(out.encode("ascii", errors="replace").decode("ascii"))
ssh.close()
