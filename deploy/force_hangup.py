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
$key = config('integrations.morpheus.api_key');
$host = config('integrations.morpheus.host');
foreach (['19c779cb-76de-467a-bc84-2d472549b2d6','66ae754b-d496-4815-90bf-64415187d4d5'] as $uuid) {
  $r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->timeout(15)->post("https://{$host}/api/v1/call-control/calls/{$uuid}/hangup");
  echo "$uuid HTTP {$r->status()} {$r->body()}\n";
}
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
  ->get("https://{$host}/api/v1/call-control/calls");
echo "Active after hangup: {$r->body()}\n";
"""

ssh = connect()
tmp = "/tmp/force-hangup.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
