#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, r"""
cd /var/www/apexone
php artisan tinker --execute="
\$rows = App\Models\User::query()->orderBy('id')->limit(15)->get(['id','name','email','is_super_admin']);
foreach (\$rows as \$u) {
  echo \$u->id.' | '.\$u->email.' | super='.(int)(\$u->is_super_admin ?? 0).PHP_EOL;
}
"
""", check=False))
finally:
    ssh.close()
