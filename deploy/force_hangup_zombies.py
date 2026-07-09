#!/usr/bin/env python3
"""Try every hangup path for zombie calls."""
import sys
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
$base = "https://{$host}/api/v1/call-control";
$uuids = ['19c779cb-76de-467a-bc84-2d472549b2d6','66ae754b-d496-4815-90bf-64415187d4d5','c5d46edf-0748-43b2-b08d-ec4564f7f0b0'];
foreach ($uuids as $uuid) {
    foreach (['hangup','unbridge'] as $action) {
        $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
            ->timeout(15)->post("{$base}/calls/{$uuid}/{$action}");
        echo "{$uuid} {$action} HTTP {$r->status()} {$r->body()}\n";
    }
}
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])->get("{$base}/calls");
echo "\nActive: ".$r->body()."\n";
"""

ssh = connect()
tmp = "/tmp/force-hangup2.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f:
    f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
