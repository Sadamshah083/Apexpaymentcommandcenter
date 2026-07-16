#!/usr/bin/env python3
"""Fix admin workspace + verify monitoring roster on ApexPayments."""
from __future__ import annotations

import os
import shlex
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

PHP = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

$wsAgents = Workspace::find(2);
$wsEmpty = Workspace::find(1);
$admin = User::find(1);
$super = User::find(2);

if ($wsAgents && $admin) {
    // Ensure admin can see ApexPayments agents.
    if (! $wsAgents->users()->where('user_id', $admin->id)->exists()) {
        $wsAgents->users()->attach($admin->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        echo "attached admin to workspace 2\n";
    } else {
        echo "admin already on workspace 2\n";
    }
    $admin->update(['current_workspace_id' => 2]);
    echo "admin current_workspace_id=2\n";
}

if ($wsAgents && $super) {
    $super->update(['current_workspace_id' => 2]);
    echo "superadmin current_workspace_id=2\n";
}

// Ensure orphaned team accounts are on workspace 2 if they exist.
$orphans = User::query()->whereIn('id', [4,5,6,7,8,9,10,11,12,13,20])->get();
foreach ($orphans as $u) {
    $on = DB::table('workspace_user')->where('workspace_id', 2)->where('user_id', $u->id)->exists();
    echo "orphan id={$u->id} name={$u->name} on_ws2=".($on ? 'yes' : 'no')."\n";
}

$mon = app(App\Services\Communications\CallMonitoringService::class);
$snap = $mon->snapshot($wsAgents, light: true);
echo "ws2 light rows_tables_not_logged_in=".count($snap['tables']['not_logged_in'] ?? [])." total_summary=".($snap['summary']['total'] ?? 0)."\n";
foreach (($snap['tables']['not_logged_in'] ?? []) as $r) {
    echo "  OFFLINE user={$r['user']} station={$r['station']} role={$r['role_label']}\n";
}
$roster = app(App\Services\Communications\CommunicationsAgentService::class)->listMonitorableDirectory($wsAgents);
echo "ws2 monitorable=".count($roster)."\n";
foreach ($roster as $a) {
    echo "  roster {$a['name']} role={$a['role']} ext=".($a['morpheus_extension_num'] ?? '-')."\n";
}
'''

local = ROOT / "deploy" / "_fix_admin_workspace.php"
local.write_text(PHP, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(
    ssh,
    [
        (local, "deploy/_fix_admin_workspace.php"),
        (ROOT / "app/Services/Communications/CallMonitoringService.php", "app/Services/Communications/CallMonitoringService.php"),
        (ROOT / "app/Services/Communications/CommunicationsAgentService.php", "app/Services/Communications/CommunicationsAgentService.php"),
    ],
    app_root="/var/www/apexone",
)
print(sudo_run(ssh, "cd /var/www/apexone && php -l app/Services/Communications/CallMonitoringService.php && sudo -u www-data php deploy/_fix_admin_workspace.php && php artisan cache:clear"))
ssh.close()
