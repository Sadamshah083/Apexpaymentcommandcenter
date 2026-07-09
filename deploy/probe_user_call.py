#!/usr/bin/env python3
"""Probe a specific Morpheus call UUID from user SIP logs."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = sys.argv[1] if len(sys.argv) > 1 else "c191027c-f4cd-123f-eab3-00163e23a70c"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$snap = $api->getCall('{UUID}');
echo "=== getCall ===\\n".json_encode($snap, JSON_PRETTY_PRINT)."\\n";
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 80]);
foreach ($r->json('cdr') ?? [] as $row) {{
    $blob = json_encode($row);
    if (($row['call_uuid'] ?? '') === '{UUID}' || str_contains($blob, '{UUID}')) {{
        echo "=== CDR match ===\\n".$blob."\\n";
    }}
}}
"""

def main() -> int:
    ssh = connect()
    tmp = "/tmp/probe-user-call.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
    ssh.close()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
