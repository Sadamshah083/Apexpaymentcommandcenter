#!/usr/bin/env python3
"""Probe a Morpheus call UUID and related CDR legs on production."""
import base64, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "3ada9d00-ecfb-4e46-bb9d-be86bba1f7df"

PHP = rf"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$uuid = '{UUID}';
$client = (new ReflectionClass($zoom))->getMethod('client');
$client->setAccessible(true);
$http = $client->invoke($zoom);
$urlMethod = (new ReflectionClass($zoom))->getMethod('url');
$urlMethod->setAccessible(true);
$live = $http->get($urlMethod->invoke($zoom, "/calls/" . $uuid));
$cdr = $http->get($urlMethod->invoke($zoom, '/cdr'), ['limit' => 100, 'search' => $uuid]);
$logs = $zoom->listCdr(['limit' => 20])['logs'] ?? [];
$normalized = null;
foreach ($logs as $log) {{
    if (($log['id'] ?? '') === $uuid) {{
        $normalized = $log;
        break;
    }}
}}
echo json_encode([
    'uuid' => $uuid,
    'live_get_status' => $live->status(),
    'live_get_body' => $live->json(),
    'snapshot' => $zoom->getCall($uuid),
    'destination_answered' => $zoom->destinationAnsweredOnCall($uuid, '+12722001232'),
    'cdr_raw_matches' => $cdr->json('cdr') ?? [],
    'normalized_log' => $normalized,
    'recent_same_did_rows' => array_values(array_filter($logs, fn ($l) => ($l['to_phone'] ?? '') === ($l['from_phone'] ?? '') && ($l['from_phone'] ?? '') !== '')),
], JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
