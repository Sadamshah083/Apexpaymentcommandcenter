#!/usr/bin/env python3
"""Probe Morpheus active calls + hangup API on production."""
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;

$api = app(ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{$host}/api/v1/call-control";

echo "=== ACTIVE CALLS (Morpheus API) ===\n";
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->timeout(10)->get("{$base}/calls");
echo "HTTP {$r->status()}\n";
$calls = $r->json('calls') ?? [];
echo "count=" . count($calls) . "\n";
foreach ($calls as $c) {
    echo json_encode([
        'uuid' => $c['uuid'] ?? $c['call_uuid'] ?? null,
        'state' => $c['state'] ?? $c['status'] ?? null,
        'from' => $c['from'] ?? $c['caller_id_number'] ?? null,
        'to' => $c['destination_number'] ?? $c['destination'] ?? $c['to'] ?? null,
        'extension' => $c['extension'] ?? $c['extension_num'] ?? null,
        'bridged_to' => $c['bridged_to'] ?? null,
        'billsec' => $c['billsec'] ?? 0,
        'live' => $c['live'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";
}

echo "\n=== RECENT CALL LOG + hubCallStatus ===\n";
$log = CommunicationCallLog::orderByDesc('id')->first();
if ($log) {
    $uuid = (string) ($log->morpheus_call_uuid ?? '');
    echo "log id={$log->id} ext={$log->from_extension} dest={$log->destination} uuid={$uuid}\n";
    if ($uuid !== '') {
        $status = $api->hubCallStatus($uuid, $log->destination);
        echo "hubCallStatus: " . json_encode($status, JSON_UNESCAPED_SLASHES) . "\n";
        $legs = (new ReflectionClass($api))->getMethod('findCdrLegsByUuid');
        $legs->setAccessible(true);
        $cdrLegs = $legs->invoke($api, $uuid);
        echo "cdr legs count=" . count($cdrLegs) . "\n";
        foreach ($cdrLegs as $leg) {
            echo "  leg dest=" . ($leg['destination_number'] ?? '?') . " billsec=" . ($leg['billsec'] ?? 0)
                . " cause=" . ($leg['hangup_cause'] ?? '') . " uuid=" . ($leg['call_uuid'] ?? '') . "\n";
        }
    }
}

echo "\n=== RELEASE EXT 1020 (dry list only) ===\n";
$ref = new ReflectionClass($api);
$list = $ref->getMethod('listActiveCalls');
$list->setAccessible(true);
$touch = $ref->getMethod('activeCallTouchesExtension');
$touch->setAccessible(true);
foreach ($list->invoke($api) as $c) {
    if ($touch->invoke($api, $c, '1020')) {
        echo "matches 1020: " . json_encode($c, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
"""

ssh = connect()
tmp = "/tmp/probe-hangup.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
print("\n=== NGINX hangup/status (recent) ===")
print(sudo_run(ssh, "grep -E 'hangup|release-extension|morpheus/calls' /var/log/nginx/access.log | tail -30", check=False))
ssh.close()
