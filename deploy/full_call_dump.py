#!/usr/bin/env python3
"""Full Morpheus call + CDR leg dump for one UUID."""
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "e07343e4-8b05-42db-a754-7bcd586c0795"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$uuid = '{UUID}';

echo "=== getCall ===\\n";
echo json_encode($api->getCall($uuid), JSON_PRETTY_PRINT)."\\n";

echo "=== resolveCallSnapshot ===\\n";
$ref = new ReflectionClass($api);
$m = $ref->getMethod('resolveCallSnapshot');
$m->setAccessible(true);
echo json_encode($m->invoke($api, $uuid), JSON_PRETTY_PRINT)."\\n";

echo "=== destinationAnsweredOnCall ===\\n";
echo json_encode([
    '12722001232' => $api->destinationAnsweredOnCall($uuid, '+12722001232'),
    '2722001232' => $api->destinationAnsweredOnCall($uuid, '12722001232'),
], JSON_PRETTY_PRINT)."\\n";

$r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 100]);
$matches = [];
foreach ($r->json('cdr') ?? [] as $row) {{
    $blob = json_encode($row);
    if (($row['call_uuid'] ?? '') === $uuid
        || ($row['bridged_to'] ?? '') === $uuid
        || str_contains($blob, $uuid)) {{
        $matches[] = $row;
    }}
}}
echo "=== CDR rows (".count($matches).") ===\\n";
echo json_encode($matches, JSON_PRETTY_PRINT)."\\n";
"""

ssh = connect()
tmp = "/tmp/full-call-dump.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
