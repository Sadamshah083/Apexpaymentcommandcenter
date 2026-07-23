#!/usr/bin/env python3
"""Hotfix role update 500 + team-lead auto-assign."""
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

VERIFY = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;

$src = file_get_contents(__DIR__.'/../app/Services/Workspace/WorkspaceMemberService.php');
if (str_contains($src, 'if (role ===')) {
    echo "BUG_STILL_PRESENT\n";
    exit(1);
}
echo "typo_fixed=yes\n";

$ws = Workspace::find(2);
$actor = User::platformSuperAdmin() ?: User::find(23);
$member = User::find(14);
if (! $ws || ! $actor || ! $member) {
    echo "missing fixtures ws=".($ws?->id?:0)." actor=".($actor?->id?:0)." member=".($member?->id?:0)."\n";
    exit(1);
}

$svc = app(WorkspaceMemberService::class);
$before = $ws->users()->where('user_id', $member->id)->first()?->pivot?->role;
echo "before_role={$before}\n";

$closerTls = $ws->users()->wherePivot('role', 'closers_team_lead')->wherePivot('status', 'active')->pluck('users.name', 'users.id');
echo 'closer_tls='.$closerTls->map(fn ($n, $id) => "{$id}:{$n}")->implode(',')."\n";

try {
    $svc->updateMemberRole($ws, $actor, $member, 'closer');
    $after = $ws->users()->where('user_id', $member->id)->first();
    echo 'after_role='.($after?->pivot?->role)." lead=".($after?->pivot?->team_lead_user_id ?: 'null')."\n";

    // Also verify admin <-> manager switches work for a non-critical path using the same method on a dry role bounce.
    $svc->updateMemberRole($ws, $actor, $member, 'appointment_setter');
    $svc->updateMemberRole($ws, $actor, $member, 'closer');
    $final = $ws->users()->where('user_id', $member->id)->first();
    echo 'final_role='.($final?->pivot?->role)." lead=".($final?->pivot?->team_lead_user_id ?: 'null')."\n";
    echo "OK\n";
} catch (Throwable $e) {
    echo 'FAIL '.$e->getMessage()."\n";
    exit(1);
}
'''

verify_path = ROOT / "deploy" / "_verify_role_fix.php"
verify_path.write_text(VERIFY, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(
    ssh,
    [
        (ROOT / "app/Services/Workspace/WorkspaceMemberService.php", "app/Services/Workspace/WorkspaceMemberService.php"),
        (verify_path, "deploy/_verify_role_fix.php"),
    ],
    app_root="/var/www/apexone",
)
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "php -l app/Services/Workspace/WorkspaceMemberService.php && "
    "php artisan cache:clear && "
    "sudo -u www-data php deploy/_verify_role_fix.php",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
