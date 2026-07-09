#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

uuids = [
    "0d79119a-25bf-4f4b-81cd-07936edd5145",  # failed billsec=0
    "ccddd196-05e4-4e09-bd10-ae0072d46109",  # success billsec=7
    "4139a444-6ce8-4f93-b7dd-fed4e39340bf",  # latest
]
PHP = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$uuids = """ + str(uuids).replace("'", '"') + """;
foreach ($uuids as $uuid) {
    echo "\\n=== $uuid ===\\n";
    echo "getCall: ".json_encode($api->getCall($uuid), JSON_PRETTY_PRINT)."\\n";
    $r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
        ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 30]);
    foreach ($r->json('cdr') ?? [] as $row) {
        if (($row['call_uuid'] ?? '') === $uuid) {
            echo "CDR: ".json_encode($row, JSON_PRETTY_PRINT)."\\n";
            break;
        }
    }
    $dest = $api->destinationAnsweredOnCall($uuid, '+12722001232');
    echo "destinationAnsweredOnCall: ".($dest ? 'true' : 'false')."\\n";
}
"""
ssh = connect()
tmp = "/tmp/cdr-detail.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
