#!/usr/bin/env python3
"""Show all CDR legs for recent outbound calls (internal SIP vs PSTN)."""
import base64, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$client = (new ReflectionClass($zoom))->getMethod('client');
$client->setAccessible(true);
$http = $client->invoke($zoom);
$urlMethod = (new ReflectionClass($zoom))->getMethod('url');
$urlMethod->setAccessible(true);
$response = $http->get($urlMethod->invoke($zoom, '/cdr'), ['limit' => 80]);
$rows = $response->json('cdr') ?? [];
$byUuid = [];
foreach ($rows as $row) {
    $uuid = (string) ($row['call_uuid'] ?? '');
    if ($uuid === '') continue;
    $byUuid[$uuid][] = [
        'caller_id_number' => $row['caller_id_number'] ?? null,
        'destination_number' => $row['destination_number'] ?? null,
        'agent_extension' => $row['agent_extension'] ?? null,
        'billsec' => $row['billsec'] ?? null,
        'duration_sec' => $row['duration_sec'] ?? null,
        'hangup_cause' => $row['hangup_cause'] ?? null,
        'call_outcome' => $row['call_outcome'] ?? null,
        'direction' => $row['direction'] ?? null,
    ];
}
$out = [];
foreach (array_slice($byUuid, 0, 8, true) as $uuid => $legs) {
    $out[] = ['call_uuid' => $uuid, 'leg_count' => count($legs), 'legs' => $legs];
}
echo json_encode($out, JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
