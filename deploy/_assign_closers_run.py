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

foreach (['ElijahMorgan', 'Jacob Khan'] as $name) {
    $user = User::where('name', $name)->first();
    if (! $user) {
        echo "skip {$name}\n";
        continue;
    }
    $svc->updateMemberRole($ws, $actor, $user, 'closer');
    $pivot = $ws->users()->where('user_id', $user->id)->first()->pivot;
    echo "{$name} role={$pivot->role} lead=".($pivot->team_lead_user_id ?: 'null')."\n";
}
echo "OK\n";
'''
path = ROOT / "deploy" / "_assign_closers_to_tl.php"
path.write_text(php, encoding="utf-8", newline="\n")
ssh = connect()
upload_files(ssh, [(path, "deploy/_assign_closers_to_tl.php")], app_root="/var/www/apexone")
out = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php deploy/_assign_closers_to_tl.php")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
