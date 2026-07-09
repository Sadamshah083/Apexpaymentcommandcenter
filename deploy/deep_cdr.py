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
$api = app(App\Services\Integrations\ZoomApiService::class);
$uuids = [
  '7b539699-05d4-4278-85a7-c9327c33c8f4',
  'b6e4e516-43be-41d7-9d7c-8113f11da733',
  'bdd48334-0e3c-45e4-a2ff-c99668335048',
];
$r = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/cdr", ['limit' => 20]);
foreach ($uuids as $uuid) {
  echo "\n=== $uuid ===\n";
  foreach ($r->json('cdr') ?? [] as $row) {
    if (($row['call_uuid'] ?? '') === $uuid) {
      echo json_encode($row, JSON_PRETTY_PRINT)."\n";
    }
  }
}
echo "\nACTIVE CALLS:\n";
$live = Illuminate\Support\Facades\Http::withHeaders(['X-API-Key' => $key])
    ->get("https://".$host."/api/v1/call-control/calls");
echo $live->body()."\n";
"""

ssh = connect()
tmp = "/tmp/deep-cdr.php"
sftp = ssh.open_sftp()
with sftp.file(tmp, "w") as f: f.write(PHP)
sftp.close()
print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php {tmp}", check=False))
ssh.close()
