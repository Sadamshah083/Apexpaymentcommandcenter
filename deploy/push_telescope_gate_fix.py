#!/usr/bin/env python3
"""Fix Telescope 403 by allowing admin portal users."""
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

ssh = connect()
try:
    upload_files(ssh, [
        (ROOT / "app/Providers/TelescopeServiceProvider.php", "app/Providers/TelescopeServiceProvider.php"),
    ])
    print(sudo_run(ssh, r"""
cd /var/www/apexone
php artisan optimize:clear
grep -n 'canAccessAdminPortal\|TELESCOPE_EMAILS' app/Providers/TelescopeServiceProvider.php
php artisan tinker --execute="
\$admins = App\Models\User::query()->whereHas('workspaces', function (\$q) {
  \$q->whereIn('workspace_user.role', ['super_admin','admin','manager']);
})->limit(8)->get(['id','name','email']);
foreach (\$admins as \$u) {
  echo \$u->id.' | '.\$u->email.' | can='.(int)\$u->canAccessAdminPortal().PHP_EOL;
}
"
"""))
    print("Telescope gate fixed. Login as admin then open /telescope")
finally:
    ssh.close()
