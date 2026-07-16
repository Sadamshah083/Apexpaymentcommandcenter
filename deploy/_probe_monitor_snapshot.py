#!/usr/bin/env python3
"""Probe production call-monitoring snapshot shape and agent directory."""
from __future__ import annotations

import json
import shlex

import paramiko

HOST, USER, PW = "203.215.161.236", "ateg", "balitech1"
APP = "/var/www/apexone"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)
    cmd = rf"""
cd {APP}
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$ws = App\Models\Workspace::query()->orderBy("id")->first();
echo "workspace=" . ($ws?->id ?? "none") . " name=" . ($ws?->name ?? "") . PHP_EOL;
$agents = app(App\Services\Communications\CommunicationsAgentService::class)->listLocalExtensionDirectory($ws);
echo "local_agents=" . count($agents) . PHP_EOL;
foreach (array_slice($agents, 0, 15) as $a) {{
  echo " agent id={{$a[\"user_id\"]}} name={{$a[\"name\"]}} role={{$a[\"role\"]}} ext={{$a[\"morpheus_extension_num\"]}} label={{$a[\"role_label\"]}}\\n";
}}
$full = app(App\Services\Communications\CommunicationsAgentService::class)->listForWorkspace($ws);
echo "full_agents=" . count($full) . PHP_EOL;
$mon = app(App\Services\Communications\CallMonitoringService::class);
$snap = $mon->snapshot($ws, light: false);
echo "rows=" . count($snap["rows"] ?? []) . " summary=" . json_encode($snap["summary"] ?? []) . PHP_EOL;
echo "warnings=" . json_encode($snap["warnings"] ?? []) . PHP_EOL;
foreach (array_slice($snap["rows"] ?? [], 0, 20) as $r) {{
  echo " row user={{$r[\"user\"]}} status={{$r[\"status\"]}} bucket={{$r[\"bucket\"]}} station={{$r[\"station\"]}}\\n";
}}
$online = app(App\Services\Communications\AgentPresenceService::class)->listOnline($ws);
echo "online=" . count($online) . PHP_EOL;
foreach (array_slice($online, 0, 10) as $o) {{
  echo " online id={{$o[\"user_id\"]}} name={{$o[\"name\"]}} role={{$o[\"role\"]}} ext={{$o[\"extension\"]}}\\n";
}}
'
"""
    full = f"echo {shlex.quote(PW)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full, timeout=120)
    print((o.read() + e.read()).decode(errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
