#!/usr/bin/env python3
"""Fix Call Monitoring agent load on NEW + verify not_logged_in roster."""
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
    "app/Services/Communications/CommunicationsAgentService.php",
]

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workspace;
use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\CommunicationsAgentService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

$workspace = Workspace::query()->where("name", "ApexPayments")->first();
$admin = User::query()->whereRaw("LOWER(email)=?", ["admin@apexonepayments.com"])->first()
    ?? User::query()->orderBy("id")->first();
Auth::login($admin);

// Purge broken cache keys for all workspaces
foreach (Workspace::query()->pluck("id") as $wid) {
    Cache::forget("ws:".$wid.":active_members_v1");
    app(CommunicationsAgentService::class)->forgetActiveWorkspaceMembersCache((int)$wid);
}
Cache::flush();

$svc = app(CommunicationsAgentService::class);
$ref = new ReflectionClass($svc);
$prop = $ref->getProperty("activeMembersRequestMemo");
$prop->setAccessible(true);
$prop->setValue(null, []);

$first = $svc->loadActiveWorkspaceMembers($workspace);
$prop->setValue(null, []);
$second = $svc->loadActiveWorkspaceMembers($workspace);
echo "second_ok=".($second->count())." pivot=".($second->first()->pivot->role ?? "none")."\n";

$snap = app(CallMonitoringService::class)->snapshot($workspace, light: true);
echo "warnings=".json_encode($snap["warnings"] ?? [])."\n";
echo "summary=".json_encode($snap["summary"] ?? [])."\n";
echo "not_logged_in=".count($snap["not_logged_in"] ?? [])."\n";
echo "not_in_call=".count($snap["not_in_call"] ?? [])."\n";
echo "rows=".count($snap["rows"] ?? [])."\n";
if (!empty($snap["not_logged_in"][0])) {
    echo "sample_offline=".json_encode($snap["not_logged_in"][0])."\n";
}
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    upload_files(ssh, [(ROOT / f, f) for f in FILES], app_root=REMOTE)

    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"""
set -e
cd {REMOTE}
chown www-data:www-data {' '.join(REMOTE + '/' + f for f in FILES)}
php -l app/Services/Communications/CommunicationsAgentService.php
sudo -u www-data php artisan cache:clear
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || true
echo {enc} | base64 -d | sudo -u www-data php
echo FIXED
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=180)
    print((o.read() + e.read()).decode(errors="replace")[-10000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
