#!/usr/bin/env python3
"""Test hangup on a live PSTN leg (direct originate, no browser)."""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;

$api = app(ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$ext = '1020';
$dest = '12722001232';
$did = config('integrations.communications.default_outbound_did') ?: '13133851223';
$campaign = config('integrations.morpheus.default_campaign_id');

function activeCount($base, $key) {
    $r = Http::withHeaders(['X-API-Key' => $key])->timeout(8)->get("{$base}/calls");
    return count($r->json('calls') ?? []);
}

$api->releaseExtensionCallsWithDestination($ext, "+{$dest}");
sleep(1);

echo "=== DIRECT PSTN ORIGINATE ===\n";
$r = Http::withHeaders(['X-API-Key' => $key])->timeout(25)->post("{$base}/calls/originate", [
    'from' => $ext,
    'to' => $dest,
    'timeout_sec' => 90,
    'campaign_id' => $campaign,
    'caller_id_number' => preg_replace('/\D/', '', $did),
]);
echo "HTTP {$r->status()} {$r->body()}\n";
$uuid = (string) $r->json('call_uuid');
if ($uuid === '') exit(1);

$liveSeen = false;
for ($i = 0; $i < 12; $i++) {
    sleep(2);
    $active = activeCount($base, $key);
    $snap = Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls/{$uuid}");
    $live = (bool) ($snap->json('live') ?? false);
    $status = $api->hubCallStatus($uuid, "+{$dest}");
    echo "t=".($i*2)."s active={$active} live=".json_encode($live)
        ." billsec=".($status['billsec']??0)." ended=".json_encode($status['call_ended']??false)."\n";
    if ($live || $active > 0) $liveSeen = true;
    if ($liveSeen && ($active > 0 || $live)) {
        echo "\n=== HANGUP WHILE LIVE ===\n";
        $hang = $api->hangupWithContext($uuid, $ext, "+{$dest}", [$uuid]);
        echo json_encode($hang)."\n";
        sleep(2);
        $after = activeCount($base, $key);
        echo "active after hangup={$after}\n";
        echo ($after === 0 ? "PASS: live hangup cleared active calls\n" : "FAIL: calls still active\n");
        exit($after === 0 ? 0 : 2);
    }
    if (($status['call_ended'] ?? false) === true) break;
}

echo "No live window — final release\n";
echo json_encode($api->releaseExtensionCallsWithDestination($ext, "+{$dest}"))."\n";
echo "SKIP: extension did not create live call (USER_BUSY?)\n";
"""

ssh = connect()
tmp = "/tmp/e2e-live-hangup.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
