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
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
foreach (['1001','1020'] as $ext) {
  echo "\n=== ext $ext ===\n";
  $r = $api->originateCall($ext, '+12722001232', $agents->extensionDialOptions($ext));
  echo json_encode($r)."\n";
  if ($uuid = $r['call_uuid'] ?? null) {
    sleep(14);
    echo "cdr: ".json_encode($api->getCall($uuid))."\n";
  }
}
"""

ssh = connect()
tmp = "/tmp/test-ext.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
