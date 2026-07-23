#!/usr/bin/env python3
"""Verify John William appears in UM member list query."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\User; use App\Models\Workspace;
$ws = Workspace::find(2);
$members = $ws->users()->orderBy("users.name")->get(["users.id","users.name","users.email"]);
$john = $members->first(fn($m)=>stripos($m->email,"johnwilliam")!==false);
echo "TOTAL=".$members->count()."\n";
echo "JOHN=".json_encode($john?->only(["id","name","email"]))."\n";
echo "PIVOT=".json_encode(optional($ws->users()->where("user_id",$john?->id)->first())->pivot)."\n";
$names = $members->pluck("name")->values();
$idx = $names->search("John William");
echo "INDEX_IN_SORTED_LIST=".$idx."\n";
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    enc = base64.b64encode(PHP.encode()).decode()
    inner = f"cd /var/www/apexone && echo {enc} | base64 -d | sudo -u www-data php"
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=60)
    print((o.read() + e.read()).decode(errors="replace")[-4000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
