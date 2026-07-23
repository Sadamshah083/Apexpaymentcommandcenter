#!/usr/bin/env python3
"""Deploy closer-team roles, email login, password hints, green login UI."""

from __future__ import annotations

import os
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "database/migrations/2026_07_16_234500_add_password_hint_to_users_table.php",
    "app/Models/User.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "app/Http/Controllers/WorkspaceAuthController.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/auth/login_admin.blade.php",
    "resources/views/auth/login_portal.blade.php",
    "resources/js/member-management.js",
    "resources/css/app.css",
]

SETUP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasColumn('users', 'password_hint')) {
    Schema::table('users', function ($table) {
        $table->string('password_hint')->nullable()->after('password');
    });
    echo "added password_hint column\n";
}

$ws = Workspace::where('name', 'ApexPayments')->first() ?: Workspace::find(2);
if (! $ws) {
    echo "workspace missing\n";
    exit(1);
}

$damon = User::query()
    ->where(function ($q) {
        $q->whereRaw('LOWER(email)=?', ['damonpeterson@apexonepayments.com'])
          ->orWhere('name', 'damonpeterson');
    })
    ->first();

if (! $damon) {
    echo "damonpeterson missing\n";
    exit(1);
}

// damon -> B2B Closer Team Lead
$ws->users()->updateExistingPivot($damon->id, [
    'role' => 'closers_team_lead',
    'team_lead_user_id' => null,
    'status' => 'active',
]);
echo "damon role=closers_team_lead\n";

// His current team members -> B2B Closers under damon
$memberIds = DB::table('workspace_user')
    ->where('workspace_id', $ws->id)
    ->where('team_lead_user_id', $damon->id)
    ->pluck('user_id');

foreach ($memberIds as $memberId) {
    $ws->users()->updateExistingPivot($memberId, [
        'role' => 'closer',
        'team_lead_user_id' => $damon->id,
        'status' => 'active',
    ]);
    $u = User::find($memberId);
    echo "closer member={$u?->name} <{$u?->email}>\n";
}

// Sequential passwords for all active workspace members (admins + team)
$members = $ws->users()
    ->wherePivot('status', 'active')
    ->orderBy('users.id')
    ->get();

$n = 1;
$rows = [];
foreach ($members as $user) {
    $plain = 'balitech@' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    // password cast hashes automatically
    $user->forceFill([
        'password' => $plain,
        'password_hint' => $plain,
    ])->save();

    $role = $user->pivot->role ?? '';
    $tl = $user->pivot->team_lead_user_id ?? '';
    $rows[] = [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $role,
        'team_lead' => $tl,
        'password' => $plain,
        'portal' => in_array($role, ['super_admin', 'admin', 'manager'], true) ? 'admin' : 'portal',
    ];
    echo "pwd {$user->email} => {$plain} role={$role}\n";
    $n++;
}

file_put_contents('/tmp/apex_login_table.json', json_encode($rows, JSON_PRETTY_PRINT));
echo "DONE members=".count($rows)."\n";
"""


def main() -> None:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES])
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_closer_setup.php", "w") as f:
            f.write(SETUP)
        sftp.close()

        print(sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && php artisan migrate --force --path=database/migrations/2026_07_16_234500_add_password_hint_to_users_table.php",
            f"cd {REMOTE_APP} && php /tmp/apex_closer_setup.php",
            f"cd {REMOTE_APP} && npm run build",
            f"cd {REMOTE_APP} && chown -R www-data:www-data public/build resources/views",
            f"cd {REMOTE_APP} && php artisan view:clear",
            f"cd {REMOTE_APP} && php artisan config:clear",
            f"cd {REMOTE_APP} && php artisan cache:clear",
            "systemctl reload php8.3-fpm 2>/dev/null || true",
        ], check=False))

        print("--- login table ---")
        print(sudo_run(ssh, "cat /tmp/apex_login_table.json", check=False))
        print("--- verify roles ---")
        print(sudo_run(ssh, "php /tmp/apex_list_users.php 2>/dev/null || true", check=False))
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
