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
$ws = App\Models\Workspace::find(2);
foreach ($ws->users()->orderBy('users.name')->get() as $u) {
    echo $u->id."\t".$u->name."\t".$u->pivot->role."\t".($u->pivot->status)."\tlead=".($u->pivot->team_lead_user_id?:'-')."\n";
}
'''
path = ROOT / "deploy" / "_list_ws2_members.php"
path.write_text(php, encoding="utf-8", newline="\n")
ssh = connect()
upload_files(ssh, [(path, "deploy/_list_ws2_members.php")], app_root="/var/www/apexone")
out = sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php deploy/_list_ws2_members.php")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
