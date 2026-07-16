#!/usr/bin/env python3
"""Inspect users / workspace membership on production."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_HOST", "203.215.161.236")
os.environ.setdefault("DEPLOY_USER", "ateg")
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

echo "workspaces=".App\Models\Workspace::count().PHP_EOL;
foreach (App\Models\Workspace::query()->get() as $ws) {
  echo "WS id={$ws->id} name={$ws->name} users=".$ws->users()->count()." active=".$ws->users()->wherePivot('status','active')->count().PHP_EOL;
  foreach ($ws->users()->get() as $u) {
    echo "  id={$u->id} name={$u->name} email={$u->email} role={$u->pivot->role} status={$u->pivot->status} ext=".($u->pivot->morpheus_extension_num ?? '').PHP_EOL;
  }
}
echo "all_users=".App\Models\User::count().PHP_EOL;
foreach (App\Models\User::orderBy('id')->limit(80)->get() as $u) {
  echo "U id={$u->id} name={$u->name} email={$u->email}".PHP_EOL;
}
if (Illuminate\Support\Facades\Schema::hasTable('workspace_user')) {
  $rows = Illuminate\Support\Facades\DB::table('workspace_user')->orderBy('user_id')->get();
  echo "workspace_user_rows=".$rows->count().PHP_EOL;
  foreach ($rows as $r) {
    echo "  wu ws={$r->workspace_id} user={$r->user_id} role={$r->role} status={$r->status} ext=".($r->morpheus_extension_num ?? '').PHP_EOL;
  }
}
$ws = App\Models\Workspace::query()->orderBy('id')->first();
$roster = app(App\Services\Communications\CommunicationsAgentService::class)->listMonitorableDirectory($ws);
echo "monitorable_roster=".count($roster).PHP_EOL;
foreach ($roster as $a) {
  echo " roster id={$a['user_id']} name={$a['name']} role={$a['role']} ext=".($a['morpheus_extension_num'] ?? '').PHP_EOL;
}
'''

local = ROOT / "deploy" / "_probe_users.php"
local.write_text(PHP, encoding="utf-8", newline="\n")

ssh = connect()
upload_files(ssh, [(local, "deploy/_probe_users.php")], app_root="/var/www/apexone")
print(sudo_run(ssh, "cd /var/www/apexone && sudo -u www-data php deploy/_probe_users.php"))
ssh.close()
