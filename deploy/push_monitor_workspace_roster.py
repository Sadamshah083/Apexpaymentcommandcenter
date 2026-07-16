#!/usr/bin/env python3
"""Deploy monitoring workspace fallback + attach admin to agents workspace."""
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

PHP = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;

$wsAgents = Workspace::find(2);
$admin = User::find(1);
$super = User::find(2);

if ($wsAgents && $admin) {
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
    echo "admin current_workspace_id={$admin->fresh()->current_workspace_id}\n";
}
if ($wsAgents && $super) {
    $super->update(['current_workspace_id' => 2]);
    echo "superadmin current_workspace_id={$super->fresh()->current_workspace_id}\n";
}

Auth::login($admin);
$mon = app(App\Services\Communications\CallMonitoringService::class);
$resolved = $mon->resolveWorkspaceForMonitoring($admin);
echo "resolved_workspace={$resolved->id} {$resolved->name}\n";
$snap = $mon->snapshot(null, light: true);
echo "snapshot total=".($snap['summary']['total'] ?? 0)." not_logged_in=".($snap['summary']['not_logged_in'] ?? 0)."\n";
foreach (($snap['tables']['not_logged_in'] ?? []) as $r) {
    echo "  USER={$r['user']} STATION={$r['station']} ROLE={$r['role_label']}\n";
}
'''

fix_php = ROOT / "deploy" / "_fix_admin_workspace.php"
fix_php.write_text(PHP, encoding="utf-8", newline="\n")

FILES = [
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "deploy/_fix_admin_workspace.php",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
print(
    sudo_run(
        ssh,
        "cd /var/www/apexone && "
        "php -l app/Services/Communications/CallMonitoringService.php && "
        "php -l app/Http/Controllers/CallMonitoringController.php && "
        "sudo -u www-data php deploy/_fix_admin_workspace.php && "
        "php artisan cache:clear && php artisan view:clear",
    )
)
ssh.close()
print("DONE")
