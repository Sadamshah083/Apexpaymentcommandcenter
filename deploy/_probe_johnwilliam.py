#!/usr/bin/env python3
"""Probe johnwilliam user + User Management listing filters on NEW."""
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
use Illuminate\Support\Facades\DB;

$email = "johnwilliam@apexonepayments.com";
$user = User::query()->whereRaw("LOWER(email)=?", [strtolower($email)])
    ->orWhereRaw("LOWER(email) like ?", ["%johnwilliam%"])
    ->orWhereRaw("LOWER(name) like ?", ["%john%william%"])
    ->first();

echo "USER=".json_encode($user?->only(["id","name","email","password_hint","current_workspace_id","created_at","updated_at"]))."\n";

$ws = Workspace::query()->where("name","ApexPayments")->first() ?? Workspace::query()->orderBy("id")->first();
echo "WORKSPACE=".json_encode($ws?->only(["id","name"]))."\n";

if ($user) {
    $pivots = DB::table("workspace_user")->where("user_id", $user->id)->get();
    echo "PIVOTS=".json_encode($pivots)."\n";
    foreach ($pivots as $p) {
        echo "pivot_ws={$p->workspace_id} role={$p->role} status={$p->status} team_lead={$p->team_lead_user_id} ext={$p->morpheus_extension_num}\n";
    }
}

// Similar names/emails
$similar = User::query()
    ->where(function ($q) {
        $q->whereRaw("LOWER(email) like ?", ["%john%"])
          ->orWhereRaw("LOWER(name) like ?", ["%john%"])
          ->orWhereRaw("LOWER(email) like ?", ["%william%"]);
    })
    ->get(["id","name","email"]);
echo "SIMILAR=".json_encode($similar)."\n";

// How UM lists members for workspace
$members = $ws->users()->orderBy("name")->get(["users.id","users.name","users.email"]);
echo "MEMBER_COUNT=".$members->count()."\n";
$found = $members->first(fn ($m) => str_contains(strtolower($m->email), "johnwilliam") || str_contains(strtolower($m->name), "john"));
echo "FOUND_IN_WS=".json_encode($found?->only(["id","name","email"]))."\n";

// Check soft filters used by controller
echo "STATUS_BREAKDOWN=";
$rows = DB::table("workspace_user")->where("workspace_id", $ws->id)
    ->select("status", DB::raw("count(*) as c"))->groupBy("status")->get();
echo json_encode($rows)."\n";

// Recent users created
$recent = User::query()->orderByDesc("id")->limit(8)->get(["id","name","email","created_at"]);
echo "RECENT=".json_encode($recent)."\n";
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
