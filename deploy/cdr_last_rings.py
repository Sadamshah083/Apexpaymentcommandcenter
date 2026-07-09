#!/usr/bin/env python3
import sys, json
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
$uuids = ['58da9f54-d2e0-425c-a2b6-5d443c82f519','e5ce3fb0-ef50-4181-8d2f-972f2438daef','270bced0-a2ea-4f92-9fa3-8930bec429ef'];
$cr = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://{$host}/api/v1/call-control/cdr", ['limit' => 30]);
foreach ($cr->json('cdr') ?? [] as $row) {
    if (in_array($row['call_uuid'] ?? '', $uuids, true)) {
        echo json_encode($row)."\n";
    }
}
"""

ssh = connect()
tmp = "/tmp/cdr-detail.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
