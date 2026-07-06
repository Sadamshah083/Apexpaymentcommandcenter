#!/usr/bin/env python3
"""Find CDR rows where destination looks like SIP username vs PSTN for same UUID."""
import base64, json, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

SEARCH = sys.argv[1] if len(sys.argv) > 1 else "n6qiqk02"

PHP = rf"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);
$client = (new ReflectionClass($zoom))->getMethod('client');
$client->setAccessible(true);
$http = $client->invoke($zoom);
$urlMethod = (new ReflectionClass($zoom))->getMethod('url');
$urlMethod->setAccessible(true);
$response = $http->get($urlMethod->invoke($zoom, '/cdr'), ['limit' => 200, 'search' => '{SEARCH}']);
$rows = $response->json('cdr') ?? [];
$out = [];
foreach ($rows as $row) {{
    $dest = (string)($row['destination_number'] ?? '');
    $caller = (string)($row['caller_id_number'] ?? '');
    if (stripos($dest, '{SEARCH}') !== false || stripos($caller, '{SEARCH}') !== false) {{
        $out[] = [
            'call_uuid' => $row['call_uuid'] ?? null,
            'caller_id_number' => $caller,
            'destination_number' => $dest,
            'agent_extension' => $row['agent_extension'] ?? null,
            'billsec' => $row['billsec'] ?? null,
            'duration_sec' => $row['duration_sec'] ?? null,
            'hangup_cause' => $row['hangup_cause'] ?? null,
            'call_outcome' => $row['call_outcome'] ?? null,
        ];
    }}
}}
echo json_encode($out, JSON_PRETTY_PRINT);
"""

ssh = connect()
enc = base64.b64encode(PHP.encode()).decode()
print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
ssh.close()
