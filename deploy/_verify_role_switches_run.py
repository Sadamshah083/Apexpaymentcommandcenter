#!/usr/bin/env python3
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as s
s.HOST = "203.215.161.236"
s.USER = "ateg"
s.PASSWORD = "balitech1"
s.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

php = r'''<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceMemberService;

$ws = Workspace::find(2);
$actor = User::platformSuperAdmin();
$svc = app(WorkspaceMemberService::class);

// Admin <-> Manager for SherazBali
$sheraz = User::where('name', 'SherazBali')->first() ?: User::find(22);
$svc->updateMemberRole($ws, $actor, $sheraz, 'manager');
$r1 = $ws->users()->where('user_id', $sheraz->id)->first()->pivot->role;
$svc->updateMemberRole($ws, $actor, $sheraz, 'admin');
$r2 = $ws->users()->where('user_id', $sheraz->id)->first()->pivot->role;
echo "sheraz manager={$r1} admin={$r2}\n";

// Promote damonpeterson to closer TL so closer agents can be assigned, then assign Jacob.
$damon = User::where('name', 'damonpeterson')->first();
$jacob = User::where('name', 'Jacob Khan')->first();
$svc->updateMemberRole($ws, $actor, $damon, 'closers_team_lead');
$svc->updateMemberRole($ws, $actor, $jacob, 'closer');
$j = $ws->users()->where('user_id', $jacob->id)->first();
echo 'damon='.$ws->users()->where('user_id', $damon->id)->first()->pivot->role."\n";
echo 'jacob_role='.$j->pivot->role.' lead='.$j->pivot->team_lead_user_id."\n";
echo "OK\n";
'''
path = ROOT / "deploy" / "_verify_role_switches.php"
path.write_text(php, encoding="utf-8", newline="\n")
ssh = connect()
upload_files(ssh, [(path, "deploy/_verify_role_switches.php")], app_root="/var/www/apexone")
out = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php deploy/_verify_role_switches.php")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
