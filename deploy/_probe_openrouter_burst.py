#!/usr/bin/env python3
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;
$key = config('openrouter.api_key');
$base = rtrim(config('openrouter.base_url'), '/');
$ok = 0; $fail = 0;
for ($i=0; $i<8; $i++) {
  $r = Http::withToken($key)->timeout(20)->post($base.'/chat/completions', [
    'model' => 'openrouter/free',
    'messages' => [['role'=>'user','content'=>'Reply with OK']],
    'max_tokens' => 4,
  ]);
  if ($r->successful()) { $ok++; echo "ok#$i\n"; }
  else { $fail++; echo "fail#$i ".$r->status().' '.substr((string)$r->json('error.message'),0,100)."\n"; }
  usleep(200000);
}
echo "summary ok=$ok fail=$fail\n";
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_burst_free.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_burst_free.php", check=False))
finally:
    ssh.close()
