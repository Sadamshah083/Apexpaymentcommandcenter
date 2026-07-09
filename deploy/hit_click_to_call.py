#!/usr/bin/env python3
"""Fire a live Morpheus POST /click-to-call so ops can see it in logs."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1001"
DEST = sys.argv[2] if len(sys.argv) > 2 else "+12722001232"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Services\\Communications\\CommunicationsAgentService;
use App\\Services\\Integrations\\ZoomApiService;
use Illuminate\\Support\\Facades\\Http;

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);
$ext = '{EXT}';
$dest = '{DEST}';
$opts = $agents->extensionDialOptions($ext);
$digits = preg_replace('/\\D/', '', $dest);
if (strlen($digits) === 10) $digits = '1'.$digits;

$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$url = "https://{{$host}}/api/v1/call-control/click-to-call";
$body = array_merge([
    'extension' => preg_replace('/\\D/', '', $ext) ?: $ext,
    'destination' => $digits,
    'timeout_sec' => 90,
], array_filter([
    'campaign_id' => $opts['campaign_id'] ?? config('integrations.morpheus.default_campaign_id'),
    'caller_id_number' => $opts['caller_id_number'] ?? null,
], fn ($v) => filled($v)));

echo "=== POST /click-to-call ===\\n";
echo "URL: $url\\n";
echo "BODY: " . json_encode($body, JSON_PRETTY_PRINT) . "\\n";

$started = microtime(true);
$response = Http::timeout(25)->acceptJson()->withHeaders(['X-API-Key' => $key])->post($url, $body);
$ms = (int) round((microtime(true) - $started) * 1000);

echo "HTTP: " . $response->status() . " ({{$ms}}ms)\\n";
echo "RESPONSE: " . $response->body() . "\\n";

$uuid = $response->json('call_uuid');
if ($uuid) {{
    sleep(3);
    $snap = $api->getCall($uuid);
    echo "SNAPSHOT: " . json_encode($snap, JSON_PRETTY_PRINT) . "\\n";
    $api->hangup($uuid);
    echo "HANGUP sent for $uuid\\n";
}}
"""

ssh = connect()
tmp = "/tmp/hit-click-to-call.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
