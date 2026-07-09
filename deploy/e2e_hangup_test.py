#!/usr/bin/env python3
"""End-to-end hangup verification on production Morpheus API."""
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Integrations\ZoomApiService;
use App\Services\Communications\CommunicationsAgentService;

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);
$ext = '1020';
$dest = '+12722001232';
$opts = $agents->extensionDialOptions($ext);

echo "=== PRE: active calls ===\n";
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";
$pre = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls");
echo "count=" . count($pre->json('calls') ?? []) . "\n";

echo "\n=== RELEASE EXT (pre-clean) ===\n";
echo json_encode($api->releaseExtensionCallsWithDestination($ext, $dest)) . "\n";
sleep(2);

echo "\n=== ORIGINATE {$ext} -> {$dest} ===\n";
$orig = $api->originateCall($ext, $dest, $opts);
echo json_encode($orig) . "\n";
$uuid = (string) ($orig['call_uuid'] ?? '');
if ($uuid === '') {
    echo "ABORT: no call_uuid\n";
    exit(1);
}

echo "\n=== POLL STATUS (max 45s) ===\n";
$connected = false;
for ($i = 0; $i < 15; $i++) {
    sleep(3);
    $status = $api->hubCallStatus($uuid, $dest);
    $active = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls");
    $activeCount = count($active->json('calls') ?? []);
    echo "t=" . (($i + 1) * 3) . "s live=" . json_encode($status['live'] ?? null)
        . " connected=" . json_encode($status['destination_connected'] ?? false)
        . " ended=" . json_encode($status['call_ended'] ?? false)
        . " active_calls={$activeCount}\n";
    if (($status['destination_connected'] ?? false) === true || (int) ($status['billsec'] ?? 0) >= 1) {
        $connected = true;
        break;
    }
    if (($status['call_ended'] ?? false) === true) {
        break;
    }
}

echo "\n=== HANGUP WITH CONTEXT ===\n";
$hang = $api->hangupWithContext($uuid, $ext, $dest, [$uuid]);
echo json_encode($hang) . "\n";
sleep(2);

echo "\n=== POST-HANGUP active calls ===\n";
$post = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls");
$calls = $post->json('calls') ?? [];
echo "count=" . count($calls) . "\n";
foreach ($calls as $c) {
    echo json_encode([
        'uuid' => $c['uuid'] ?? $c['call_uuid'] ?? null,
        'to' => $c['destination_number'] ?? $c['to'] ?? null,
        'state' => $c['state'] ?? null,
    ]) . "\n";
}

$final = $api->hubCallStatus($uuid, $dest);
echo "\nfinal status: " . json_encode($final) . "\n";
echo "\nRESULT: " . ((count($calls) === 0 || ($final['call_ended'] ?? false)) ? 'PASS' : 'FAIL') . "\n";
"""

ssh = connect()
tmp = "/tmp/e2e-hangup.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
