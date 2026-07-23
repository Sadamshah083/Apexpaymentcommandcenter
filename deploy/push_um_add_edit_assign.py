#!/usr/bin/env python3
"""Deploy UM add/edit campaign+TL fields and table UI polish."""
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
    "app/Services/Workspace/WorkspaceMemberService.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Http/Controllers/WorkflowController.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/js/workspace-admin.js",
    "resources/css/app.css",
]

VERIFY = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
echo str_contains($src, 'teamLeadUserId') ? "create_tl_param=yes\n" : "create_tl_param=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/add-member-modal.blade.php'), 'Select campaign') ? "add_campaign=yes\n" : "add_campaign=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/add-member-modal.blade.php'), 'Select team lead') ? "add_tl=yes\n" : "add_tl=no\n";
echo str_contains(file_get_contents(__DIR__.'/../resources/views/workflows/partials/member-row.blade.php'), 'Under ') ? "under_line=yes\n" : "under_line=no\n";

$elijah = App\Models\User::where('name', 'ElijahMorgan')->first();
$damon = App\Models\User::where('name', 'damonpeterson')->first();
$ws = App\Models\Workspace::find(2);
$pivot = $ws->users()->where('user_id', $elijah->id)->first()->pivot;
echo "elijah_lead=".$pivot->team_lead_user_id." damon=".$damon->id." match=".((int)$pivot->team_lead_user_id === (int)$damon->id ? 'yes' : 'no')."\n";
echo "OK\n";
'''

verify_path = ROOT / "deploy" / "_verify_um_add_edit_assign.php"
verify_path.write_text(VERIFY, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(
    ssh,
    [(ROOT / rel, rel) for rel in FILES] + [(verify_path, "deploy/_verify_um_add_edit_assign.php")],
    app_root="/var/www/apexone",
)
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "php -l app/Http/Controllers/WorkflowController.php && "
    "php -l app/Services/Workspace/WorkspaceMemberService.php && "
    "./node_modules/.bin/vite build > /tmp/vite-um-assign.log 2>&1 && echo BUILD:$? && "
    "tail -n 6 /tmp/vite-um-assign.log | tr -cd '\\11\\12\\15\\40-\\176' && "
    "chown -R www-data:www-data public/build && "
    "php artisan view:clear && php artisan cache:clear && "
    "sudo -u www-data php deploy/_verify_um_add_edit_assign.php",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
