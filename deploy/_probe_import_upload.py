#!/usr/bin/env python3
"""Probe recent workflow uploads / failures on NEW."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workflow; use App\Models\WorkflowLead; use Illuminate\Support\Facades\DB;
$recent = Workflow::query()->orderByDesc("id")->limit(8)->get(["id","name","original_filename","status","processing_mode","total_leads","enriched_leads","campaign_id","created_at","error_message"]);
echo "RECENT=".json_encode($recent, JSON_PRETTY_PRINT)."\n";
$cols = DB::select("SHOW COLUMNS FROM workflows LIKE 'error%'");
echo "ERROR_COLS=".json_encode($cols)."\n";
$assign = DB::table("workflow_leads")
  ->select("workflow_id", "assigned_user_id", DB::raw("count(*) as c"))
  ->whereNotNull("assigned_user_id")
  ->groupBy("workflow_id","assigned_user_id")
  ->orderByDesc("workflow_id")
  ->limit(20)
  ->get();
echo "ASSIGN_BREAKDOWN=".json_encode($assign)."\n";
$log = storage_path("logs/laravel.log");
if (is_file($log)) {
  $lines = explode("\n", trim(shell_exec("tail -n 120 ".escapeshellarg($log)) ?: ""));
  foreach ($lines as $line) {
    if (stripos($line,"upload")!==false || stripos($line,"workflow")!==false || stripos($line,"Validation")!==false || stripos($line,"mimes")!==false) {
      echo "LOG: ".substr($line,0,220)."\n";
    }
  }
}
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=90)
    print((o.read() + e.read()).decode(errors="replace")[-12000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
