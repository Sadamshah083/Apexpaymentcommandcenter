#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod
    from deploy._ssh import sudo_run

    ssh_mod.HOST = NEW["host"]
    ssh_mod.USER = NEW["user"]
    ssh_mod.PASSWORD = NEW["password"]
    ssh_mod.REMOTE_APP = REMOTE

    cmd = r"""
set -e
echo '=== FPM POOL ==='
grep -E '^(pm|pm\.|request_terminate)' /etc/php/8.3/fpm/pool.d/www.conf | head -40
echo '=== HIGH CPU FPM ==='
ps -eo pid,etime,pcpu,pmem,cmd | awk '/php-fpm: pool www/ {print}' | sort -k3 -nr | head -15
echo '=== CALL EVENTS WS ==='
ps aux | grep -E 'call-events-ws|server.mjs' | grep -v grep || echo 'no call-events-ws'
ss -lntp 2>/dev/null | grep -E '8787' || true
echo '=== ACTIVE MAPS JOBS ==='
cd /var/www/apexone && php artisan tinker --execute="echo json_encode(\\App\\Models\\MapsScrapeJob::query()->whereIn('status',['running','queued','pending'])->latest('id')->limit(5)->get(['id','status','created_at','updated_at'])->toArray());" 2>/dev/null | tail -5
echo DONE_FPM_AUDIT
"""
    print(sudo_run(ssh, cmd))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
