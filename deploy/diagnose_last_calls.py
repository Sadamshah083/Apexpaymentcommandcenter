#!/usr/bin/env python3
"""Diagnose recent Morpheus CDR legs and click-to-call failures on production."""
from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$host = config('integrations.morpheus.host');
$key = config('integrations.morpheus.api_key');
$base = "https://{$host}/api/v1/call-control";
$client = fn () => Http::timeout(15)->acceptJson()->withHeaders([
    'X-API-Key' => $key,
    'Authorization' => 'Bearer ' . $key,
]);

$calls = $client()->get("{$base}/calls");
$cdr = $client()->get("{$base}/cdr", ['limit' => 15]);

$rows = [];
foreach ($cdr->json('cdr') ?? [] as $row) {
    $dest = (string) ($row['destination_number'] ?? '');
    $digits = preg_replace('/\D/', '', $dest);
    $rows[] = [
        'call_uuid' => $row['call_uuid'] ?? $row['uuid'] ?? null,
        'direction' => $row['direction'] ?? null,
        'destination_number' => $dest,
        'caller_id_number' => $row['caller_id_number'] ?? null,
        'agent_extension' => $row['agent_extension'] ?? null,
        'billsec' => $row['billsec'] ?? null,
        'hangup_cause' => $row['hangup_cause'] ?? null,
        'call_outcome' => $row['call_outcome'] ?? null,
        'is_pstn' => strlen($digits) >= 10 && !preg_match('/[a-z]/i', $dest),
        'is_agent_leg' => preg_match('/[a-z]/i', $dest) || (strlen($digits) > 0 && strlen($digits) < 10),
    ];
}

$zoom = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);

$extensions = [];
foreach (['1001', '1007', '1020'] as $ext) {
    $extensions[$ext] = [
        'endpoint_online' => $agents->extensionEndpointOnline($ext),
        'dial_options' => $agents->extensionDialOptions($ext),
    ];
}

echo json_encode([
    'active_calls_http' => $calls->status(),
    'active_calls_count' => count($calls->json('calls') ?? []),
    'recent_cdr' => $rows,
    'extensions' => $extensions,
    'dial_method' => config('integrations.morpheus.dial_method'),
    'campaign_id' => config('integrations.morpheus.default_campaign_id'),
    'outbound_did' => config('integrations.communications.default_outbound_did'),
], JSON_PRETTY_PRINT);
"""

def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
