#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = "657d06e4-d39f-4497-951f-66a44f57d8c9"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use App\\Services\\Integrations\\ZoomApiService;
$api = app(ZoomApiService::class);
$uuid = '{UUID}';
echo "getCall: " . json_encode($api->getCall($uuid), JSON_PRETTY_PRINT) . PHP_EOL;
$r = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => config('integrations.morpheus.api_key')])
    ->timeout(10)->get('https://'.config('integrations.morpheus.host').'/api/v1/call-control/cdr', ['limit' => 5]);
echo "Recent CDR:\\n";
foreach ($r->json('cdr') ?? [] as $row) {{
    echo '  '.($row['call_uuid']??'?').' ext='.($row['extension']??'?').' dest='.($row['destination_number']??'?').' cause='.($row['hangup_cause']??'?').' billsec='.($row['billsec']??0).PHP_EOL;
}}
"""

ssh = connect()
tmp = "/tmp/cdr-check.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
