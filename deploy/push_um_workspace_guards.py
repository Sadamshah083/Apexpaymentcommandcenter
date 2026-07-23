#!/usr/bin/env python3
"""Deploy User Management: icon actions, closer TL switch, workspace isolation, admin guards."""
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
    "app/Support/SalesOps.php",
    "app/Services/Workspace/WorkspaceManager.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/views/workflows/partials/create-workspace-modal.blade.php",
    "resources/js/member-management.js",
    "resources/css/app.css",
]

VERIFY = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn('users', 'password_hint')) {
    echo "MISSING password_hint column\n";
    exit(1);
}

// Prefer a single platform Super Admin (lowest id with super_admin pivot).
$saIds = DB::table('workspace_user')
    ->where('role', 'super_admin')
    ->where('status', 'active')
    ->orderBy('user_id')
    ->pluck('user_id')
    ->unique()
    ->values();

$keepId = $saIds->first();
if ($keepId) {
    $extra = $saIds->filter(fn ($id) => (int) $id !== (int) $keepId)->values();
    foreach ($extra as $extraId) {
        DB::table('workspace_user')
            ->where('user_id', $extraId)
            ->where('role', 'super_admin')
            ->update(['role' => 'admin', 'updated_at' => now()]);
        echo "demoted_extra_sa user_id={$extraId} -> admin\n";
    }
}

$super = User::platformSuperAdmin();
echo 'platform_sa='.($super?->email ?? 'none').' id='.($super?->id ?? 0).' is_platform='.($super && $super->isPlatformSuperAdmin() ? 'yes' : 'no')."\n";

$hinted = User::query()->whereNotNull('password_hint')->where('password_hint', '!=', '')->count();
$missing = User::query()->where(function ($q) {
    $q->whereNull('password_hint')->orWhere('password_hint', '');
})->count();
echo "password_hint_filled={$hinted} missing={$missing}\n";

$ws = Workspace::query()->orderBy('id')->first();
if ($ws && $super) {
    echo 'add_workspace_gate='.($super->isPlatformSuperAdmin() ? 'ok' : 'fail')."\n";
    echo 'ws_members='.$ws->users()->count()."\n";
}

echo "OK\n";
'''

verify_path = ROOT / "deploy" / "_verify_um_workspace_guards.php"
verify_path.write_text(VERIFY, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(
    ssh,
    [(ROOT / rel, rel) for rel in FILES] + [(verify_path, "deploy/_verify_um_workspace_guards.php")],
    app_root="/var/www/apexone",
)
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "php -l app/Models/User.php && "
    "php -l app/Services/Workspace/WorkspaceMemberService.php && "
    "php -l app/Services/Workspace/WorkspaceManager.php && "
    "./node_modules/.bin/vite build > /tmp/vite-um-guards.log 2>&1 && echo BUILD:$? && "
    "tail -n 8 /tmp/vite-um-guards.log | tr -cd '\\11\\12\\15\\40-\\176' && "
    "chown -R www-data:www-data public/build && "
    "php artisan view:clear && php artisan cache:clear && "
    "sudo -u www-data php deploy/_verify_um_workspace_guards.php && "
    "grep -n 'Add workspace\\|um-btn-icon-only\\|isPlatformSuperAdmin' "
    "resources/views/workflows/workspaces.blade.php "
    "resources/views/workflows/partials/member-row.blade.php "
    "app/Models/User.php | head -20",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
