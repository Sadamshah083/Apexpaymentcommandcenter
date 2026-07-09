#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

uuid = "b3e7fd33-3915-4965-8273-dc202e654577"
PHP = """<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$api = app(App\\Services\\Integrations\\ZoomApiService::class);
$uuid = '""" + uuid + """';
echo "getCall: ".json_encode($api->getCall($uuid), JSON_PRETTY_PRINT).PHP_EOL;
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 5]);
echo "Recent CDR:\\n";
foreach ($r->json('cdr') ?? [] as $row) {
    echo ($row['call_uuid']??'?').' ext='.($row['agent_extension']??'?').' dest='.($row['destination_number']??'?').' billsec='.($row['billsec']??0).' cause='.($row['hangup_cause']??'?').' start='.($row['start_time']??'?').PHP_EOL;
}
"""
ssh = connect()
tmp = "/tmp/final-cdr.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
