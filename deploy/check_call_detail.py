#!/usr/bin/env python3
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;
$api = app(ZoomApiService::class);
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
echo "LATEST CRM LOGS:\n";
foreach (CommunicationCallLog::orderByDesc('id')->limit(3)->get() as $r) {
    echo json_encode($r->only(['id','from_extension','to_phone','morpheus_call_uuid','created_at'])) . "\n";
}
foreach (['b9b056ae-10e8-40e7-9fe9-84a2a6cac4ff','7f89daab-ddcf-481e-8e90-7e1e00cc326f'] as $u) {
    echo "\nUUID {$u}:\n";
    echo 'getCall: ' . json_encode($api->getCall($u)) . "\n";
}
"""

ssh = connect()
tmp = "/tmp/call-detail2.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
