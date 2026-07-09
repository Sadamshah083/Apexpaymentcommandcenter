#!/usr/bin/env python3
"""Inspect extension state and attempt to clear USER_BUSY."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

EXT = sys.argv[1] if len(sys.argv) > 1 else "1020"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$extNum = '{EXT}';
foreach ($api->listExtensions(['limit' => 100])['extensions'] ?? [] as $ext) {{
    if ((string)($ext['extension_num'] ?? '') !== $extNum) continue;
    echo "EXT=" . json_encode($ext, JSON_PRETTY_PRINT) . PHP_EOL;
    $id = (string)($ext['id'] ?? '');
    if ($id) {{
        $patched = $api->updateExtension($id, [
            'status' => 'active',
            'password' => config('integrations.morpheus.extension_password'),
        ]);
        echo "PATCH=" . json_encode($patched, JSON_PRETTY_PRINT) . PHP_EOL;
    }}
}}
echo "CLEAR=" . json_encode($api->clearExtensionForOutboundDial($extNum, true), JSON_PRETTY_PRINT) . PHP_EOL;
$agents = app(App\\Services\\Communications\\CommunicationsAgentService::class);
$r = $api->originateCall($extNum, '+12722001232', $agents->extensionDialOptions($extNum));
echo "ORIGINATE=" . json_encode($r, JSON_PRETTY_PRINT) . PHP_EOL;
$uuid = $r['call_uuid'] ?? null;
if ($uuid) {{
    for ($i = 0; $i < 8; $i++) {{
        sleep(1);
        $s = $api->getCall($uuid) ?? [];
        echo "poll t=".($i+1)." live=".json_encode($s['live']??false)." cause=".($s['hangup_cause']??'-').PHP_EOL;
    }}
    $api->hangup($uuid);
}}
"""

ssh = connect()
tmp = "/tmp/inspect-ext.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
