#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

UUID = "7f89daab-ddcf-481e-8e90-7e1e00cc326f"

PHP = f"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$base = "https://{{$host}}/api/v1/call-control";
$uuid = '{UUID}';
$api = app(App\\Services\\Integrations\\ZoomApiService::class);

echo "ORIGINATE CONFIG:\\n";
echo 'method=' . config('integrations.morpheus.originate_method') . "\\n";
echo 'customer_first=' . (config('integrations.morpheus.originate_customer_first') ? 'true' : 'false') . "\\n";

echo "\\nGET /calls/{{uuid}}:\\n";
echo json_encode($api->getCall($uuid), JSON_PRETTY_PRINT) . "\\n";

echo "\\nCDR ROW:\\n";
$cdr = Illuminate\\Support\\Facades\\Http::withHeaders(['X-API-Key' => $key])
    ->get("{{$base}}/cdr", ['limit' => 50]);
foreach ($cdr->json('cdr') ?? [] as $row) {{
    if (($row['call_uuid'] ?? '') === $uuid) {{
        echo json_encode($row, JSON_PRETTY_PRINT) . "\\n";
    }}
}}

echo "\\nCALL LOG:\\n";
$log = App\\Models\\CommunicationCallLog::where('morpheus_call_uuid', $uuid)->first();
if ($log) echo json_encode($log->toArray(), JSON_PRETTY_PRINT) . "\\n";
"""

ssh = connect()
tmp = "/tmp/inspect-ring.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
