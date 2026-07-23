#!/usr/bin/env python3
"""Deploy UM popup create fixes + verify John William on NEW."""
from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"
FILES = [
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
    "resources/js/workspace-admin.js",
]

VERIFY = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\Workspace; use Illuminate\Support\Facades\DB;
$u = User::whereRaw("LOWER(email)=?", ["johnwilliam@apexonepayments.com"])->first();
$ws = Workspace::find(2);
$p = $u ? DB::table("workspace_user")->where("workspace_id",2)->where("user_id",$u->id)->first() : null;
echo json_encode([
  "user"=>$u?->only(["id","name","email","password_hint"]),
  "pivot"=>$p,
  "in_um_list"=>$u ? $ws->users()->where("users.id",$u->id)->exists() : false,
], JSON_PRETTY_PRINT), PHP_EOL;
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    sys.path.insert(0, str(ROOT))
    import deploy._ssh as m
    m.HOST, m.USER, m.PASSWORD, m.REMOTE_APP = NEW["host"], NEW["user"], NEW["password"], REMOTE
    from deploy._ssh import upload_files
    upload_files(ssh, [(ROOT / f, f) for f in FILES], app_root=REMOTE)
    enc = base64.b64encode(VERIFY.encode()).decode()
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {' '.join(REMOTE+'/'+f for f in FILES)}
php -l app/Http/Controllers/WorkspaceMemberController.php
php -l app/Services/Workspace/WorkspaceMemberService.php
sudo -u www-data npm run build > /tmp/vite-um-john.log 2>&1 || true
tail -n 8 /tmp/vite-um-john.log
chown -R www-data:www-data public/build
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
echo {enc} | base64 -d | sudo -u www-data php
echo DONE
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=300)
    print((o.read() + e.read()).decode(errors="replace")[-10000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
