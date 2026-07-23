#!/usr/bin/env python3
"""Probe Call Monitoring snapshot error on NEW."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"

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

$workspace = Workspace::query()->where("name", "ApexPayments")->first()
    ?? Workspace::query()->orderBy("id")->first();
$admin = User::query()->whereRaw("LOWER(email)=?", ["admin@apexonepayments.com"])->first()
    ?? User::query()->orderBy("id")->first();

Auth::login($admin);
Cache::forget("ws:".$workspace->id.":active_members_v1");

$agents = app(CommunicationsAgentService::class);
echo "MEMBERS_TRY\n";
try {
    $m = $agents->loadActiveWorkspaceMembers($workspace);
    echo "members=". $m->count() ." first_pivot=". json_encode($m->first()?->pivot?->toArray()) ."\n";
} catch (Throwable $e) {
    echo "members_ERR=". $e->getMessage() ."\n". $e->getTraceAsString() ."\n";
}

try {
    $local = $agents->listLocalExtensionDirectory($workspace);
    echo "local_ext=". count($local) ."\n";
} catch (Throwable $e) {
    echo "local_ERR=". $e->getMessage() ."\n";
}

try {
    $mon = $agents->listMonitorableDirectory($workspace);
    echo "monitorable=". count($mon) ." sample=". json_encode(array_slice($mon, 0, 2)) ."\n";
} catch (Throwable $e) {
    echo "monitor_ERR=". $e->getMessage() ."\n". $e->getFile() .":". $e->getLine() ."\n";
}

try {
    $snap = app(CallMonitoringService::class)->snapshot($workspace, light: true);
    echo "snapshot_warnings=". json_encode($snap["warnings"] ?? []) ."\n";
    echo "counts=". json_encode($snap["counts"] ?? $snap["summary"] ?? array_intersect_key($snap, array_flip(["rows","agents","not_logged_in"]))) ."\n";
    $rows = $snap["rows"] ?? $snap["agents"] ?? [];
    echo "rows=". (is_countable($rows) ? count($rows) : 0) ."\n";
    echo "keys=". implode(",", array_keys($snap)) ."\n";
} catch (Throwable $e) {
    echo "snap_ERR=". $e->getMessage() ."\n". $e->getFile() .":". $e->getLine() ."\n";
}
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"cd {REMOTE} && echo {enc} | base64 -d | sudo -u www-data php"
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=120)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
