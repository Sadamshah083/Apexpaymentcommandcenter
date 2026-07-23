#!/usr/bin/env python3
"""Probe second-cache-hit + HTTP /live for Call Monitoring on NEW."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
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

// Force populate cache, then clear request memo and read again (simulates next request).
$svc = app(CommunicationsAgentService::class);
$svc->forgetActiveWorkspaceMembersCache((int)$workspace->id);
$first = $svc->loadActiveWorkspaceMembers($workspace);
echo "first_count=".$first->count()." first_class=".get_class($first->first())."\n";

// Clear only request memo, keep Cache
$ref = new ReflectionClass($svc);
$prop = $ref->getProperty("activeMembersRequestMemo");
$prop->setAccessible(true);
$prop->setValue(null, []);

try {
    $second = $svc->loadActiveWorkspaceMembers($workspace);
    $u = $second->first();
    echo "second_count=".$second->count()." class=".($u ? get_class($u) : "null")."\n";
    echo "second_pivot=".($u && $u->pivot ? "yes role=".$u->pivot->role : "NO_PIVOT")."\n";
    $map = $svc->mapMonitorableDirectory($second, $workspace);
    echo "second_monitorable=".count($map)."\n";
} catch (Throwable $e) {
    echo "SECOND_ERR=".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n";
}

// Simulate light snapshot like /live
try {
    $snap = app(CallMonitoringService::class)->snapshot($workspace, light: true);
    echo "light_warnings=".json_encode($snap["warnings"] ?? [])."\n";
    echo "light_summary=".json_encode($snap["summary"] ?? [])."\n";
    echo "not_logged_in_rows=".count($snap["not_logged_in"] ?? [])."\n";
    echo "not_in_call_rows=".count($snap["not_in_call"] ?? [])."\n";
} catch (Throwable $e) {
    echo "LIGHT_ERR=".$e->getMessage()."\n";
}

// Full snapshot
try {
    $snap = app(CallMonitoringService::class)->snapshot($workspace, light: false);
    echo "full_warnings=".json_encode($snap["warnings"] ?? [])."\n";
    echo "full_summary=".json_encode($snap["summary"] ?? [])."\n";
} catch (Throwable $e) {
    echo "FULL_ERR=".$e->getMessage()."\n";
}

// Check laravel log tail for recent monitoring errors
$log = storage_path("logs/laravel.log");
if (is_file($log)) {
    $lines = explode("\n", trim(shell_exec("tail -n 80 ".escapeshellarg($log)) ?: ""));
    foreach ($lines as $line) {
        if (stripos($line, "agent") !== false || stripos($line, "monitor") !== false || stripos($line, "Error") !== false) {
            echo "LOG: ".substr($line, 0, 240)."\n";
        }
    }
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
    print((o.read() + e.read()).decode(errors="replace")[-14000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
