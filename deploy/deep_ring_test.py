#!/usr/bin/env python3
"""Deep ring diagnostic: active calls + CDR during originate."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1001"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$agents = app(App\\Services\\Communications\\CommunicationsAgentService::class);
$ext = '{EXT}';
$opts = $agents->extensionDialOptions($ext);

echo "endpoint_online=" . json_encode($agents->extensionEndpointOnline($ext)) . "\\n";

// NO kick — place click-to-call
$r = $api->clickToCall($ext, '+12722001232', $opts);
echo "CLICK=" . json_encode($r) . "\\n";
$uuid = $r['call_uuid'] ?? null;
if (!$uuid) exit(0);

for ($i = 0; $i < 15; $i++) {{
    sleep(1);
    $calls = $api->listCalls()['calls'] ?? [];
    $match = null;
    foreach ($calls as $c) {{
        if (($c['uuid'] ?? $c['call_uuid'] ?? '') === $uuid) $match = $c;
    }}
    $snap = $api->getCall($uuid);
    $cdr = $api->listCdr(['limit' => 3, 'call_uuid' => $uuid]);
    echo "t=".($i+1)." active=".count($calls)." match=".json_encode($match)." snap=".json_encode($snap)." cdr_count=".count($cdr['cdr']??$cdr['cdrs']??[])."\\n";
    if ($snap === null && $i > 5 && empty($calls)) break;
}}
$api->hangup($uuid);
echo "Recent CDR outbound:\\n";
foreach ($api->listCdr(['limit' => 5, 'direction' => 'outbound'])['cdr'] ?? [] as $row) {{
    echo json_encode([
        'uuid' => $row['call_uuid'] ?? $row['uuid'] ?? null,
        'dest' => $row['destination_number'] ?? $row['destination'] ?? null,
        'cause' => $row['hangup_cause'] ?? null,
        'billsec' => $row['billsec'] ?? null,
        'duration' => $row['duration_sec'] ?? null,
    ])."\\n";
}}
"""

ssh = connect()
tmp = "/tmp/deep-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
