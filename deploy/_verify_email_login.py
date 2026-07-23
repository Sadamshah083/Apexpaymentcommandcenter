#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST="203.215.161.236"; m.USER="ateg"; m.PASSWORD="balitech1"; m.REMOTE_APP="/var/www/apexone"
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Support\Facades\Hash;
foreach ([
  ['admin@apexonepayment.com','balitech@001'],
  ['damonpeterson@apexonepayments.com','balitech@007'],
  ['elijahmorgan@apexonepayments.com','balitech@004'],
] as [$email,$pwd]) {
  $u = User::whereRaw('LOWER(email)=?', [strtolower($email)])->first();
  $ok = $u && Hash::check($pwd, $u->password);
  echo ($ok?'OK':'FAIL')." {$email} hint=".($u->password_hint??'')." role=".($u?->workspaces()->first()?->pivot?->role??'')."\n";
}
"""
ssh=connect()
try:
  sftp=ssh.open_sftp()
  with sftp.file('/tmp/apex_verify_login.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_verify_login.php',check=False))
finally:
  ssh.close()
