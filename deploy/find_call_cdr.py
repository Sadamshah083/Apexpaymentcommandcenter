#!/usr/bin/env python3
import sys, time
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

for uuid in ["a7a5b6b7-691a-4a19-97f8-074bedb0c285", "657d06e4-d39f-4497-951f-66a44f57d8c9"]:
    PHP = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$uuid = '""" + uuid + """';
$r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 20]);
foreach ($r->json('cdr') ?? [] as $row) {{
    if (($row['call_uuid'] ?? '') === $uuid) {{
        echo json_encode($row, JSON_PRETTY_PRINT);
        exit;
    }}
}}
echo "UUID ".$uuid." not in last 20 CDR rows\\n";
"""
    ssh = connect()
    tmp = f"/tmp/find-{uuid[:8]}.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as f: f.write(PHP)
    sftp.close()
    print(f"=== {uuid} ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
    ssh.close()
