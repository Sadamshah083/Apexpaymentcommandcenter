#!/usr/bin/env python3
"""Originate a test Morpheus click-to-call from production server."""
from __future__ import annotations

import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

DEST = "12722001232"
EXT = "1020"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

use App\\Services\\Integrations\\ZoomApiService;
use App\\Services\\Communications\\CommunicationsAgentService;

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);

$ext = '{EXT}';
$dest = '{DEST}';
$opts = $agents->extensionDialOptions($ext);

echo "Morpheus host: " . config('integrations.morpheus.host') . PHP_EOL;
echo "Extension: $ext (online=" . ($agents->extensionEndpointOnline($ext) ? 'yes' : 'no') . ")" . PHP_EOL;
echo "Dial options: " . json_encode($opts) . PHP_EOL;
echo "Originating click-to-call to $dest..." . PHP_EOL;

$result = $api->originateCall($ext, $dest, $opts);
echo "RESULT: " . json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;

$uuid = $result['call_uuid'] ?? null;
if ($uuid) {{
    for ($i = 1; $i <= 6; $i++) {{
        sleep(3);
        $cdr = $api->getCall($uuid);
        echo "Poll $i: " . json_encode($cdr) . PHP_EOL;
        $cause = $cdr['hangup_cause'] ?? '';
        $live = $cdr['live'] ?? false;
        $billsec = (int)($cdr['billsec'] ?? 0);
        if (!$live && $cause !== '' && $cause !== 'live') {{
            break;
        }}
        if ($billsec >= 3) {{
            break;
        }}
    }}
}}
"""

ssh = connect()
tmp = "/tmp/test-originate.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()

print(f"Placing test call: ext {EXT} -> +{DEST}")
print("=" * 60)
out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False)
print(out)
ssh.close()
