#!/usr/bin/env python3
"""Probe extension 1020 busy state + compare originate vs click-to-call."""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$ext = '1020';
$dest = '12722001232';
$campaignId = config('integrations.morpheus.default_campaign_id');
$opts = $agents->extensionDialOptions($ext);

echo "=== EXT {$ext} online: ".($agents->extensionEndpointOnline($ext) ? 'yes' : 'no')."\n";
echo "=== ACTIVE CALLS ===\n";
foreach ($api->listCalls()['calls'] ?? [] as $c) {
    echo json_encode($c)."\n";
}

echo "\n=== RELEASE ALL STALE (0s) ===\n";
echo json_encode($api->releaseStaleActiveCalls(0))."\n";

echo "\n=== ACTIVE AFTER RELEASE ===\n";
foreach ($api->listCalls()['calls'] ?? [] as $c) {
    echo json_encode($c)."\n";
}

$body = array_merge([
    'from' => $ext,
    'to' => $dest,
    'timeout_sec' => 60,
    'campaign_id' => $campaignId,
], array_filter([
    'caller_id_number' => $opts['caller_id_number'] ?? null,
], fn($v) => filled($v)));

$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";

echo "\n=== POST /calls/originate ===\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->timeout(20)->post("{$base}/calls/originate", $body);
echo "HTTP {$r->status()}\n";
echo $r->body()."\n";
$uuid = $r->json('call_uuid');
if ($uuid) {
    usleep(800000);
    echo "\n=== LIVE CALL {$uuid} ===\n";
    echo json_encode($api->getCall($uuid), JSON_PRETTY_PRINT)."\n";
    usleep(1200000);
    echo "\n=== CDR AFTER 2s ===\n";
    echo json_encode($api->getCall($uuid), JSON_PRETTY_PRINT)."\n";
}
"""

ssh = connect()
tmp = "/tmp/probe-originate-busy.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
