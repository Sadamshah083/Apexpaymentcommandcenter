#!/usr/bin/env python3
"""Deploy Team Members Add/Edit/Delete visibility for Admin."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

FILES = [
    "app/Models/User.php",
    "app/Services/Workspace/WorkspaceContextService.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/css/app.css",
]

VERIFY = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = App\Models\User::where('email', 'admin@apexonepayment.com')->first();
$super = App\Models\User::where('email', 'superadmin@apexonepayment.com')->first();
$ws = App\Models\Workspace::find(2);
echo "admin_id=".($admin?->id)." role=".$admin->getWorkspaceRole(2)." can_manage=".($admin->canManageWorkspaceMembers(2)?'yes':'no')."\n";
echo "super_id=".($super?->id)." role=".$super->getWorkspaceRole(2)." can_manage=".($super->canManageWorkspaceMembers(2)?'yes':'no')."\n";
echo "ws={$ws->id} {$ws->name}\n";
'''

verify_path = ROOT / "deploy" / "_verify_member_manage.php"
verify_path.write_text(VERIFY, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(
    ssh,
    [(ROOT / rel, rel) for rel in FILES] + [(verify_path, "deploy/_verify_member_manage.php")],
    app_root="/var/www/apexone",
)
print(
    sudo_run(
        ssh,
        "cd /var/www/apexone && "
        "php -l app/Models/User.php && "
        "./node_modules/.bin/vite build > /tmp/vite-um-actions.log 2>&1 && echo BUILD:$? && "
        "tail -n 6 /tmp/vite-um-actions.log && "
        "chown -R www-data:www-data public/build && "
        "php artisan view:clear && php artisan cache:clear && "
        "sudo -u www-data php deploy/_verify_member_manage.php && "
        "grep -n 'Add account' resources/views/workflows/workspaces.blade.php | head -5 && "
        "grep -n 'um-manage-btn--labeled' resources/views/workflows/partials/member-row.blade.php | head -5",
    )
)
ssh.close()
print("DONE")
