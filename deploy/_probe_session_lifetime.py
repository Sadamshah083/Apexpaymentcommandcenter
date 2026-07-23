#!/usr/bin/env python3
from __future__ import annotations

import paramiko

HOST = "203.215.161.236"
USER = "ateg"
PASSWORD = "balitech1"


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=40)

    cmds = [
        "grep -n SESSION_ /var/www/apexone/.env | head -20",
        "php -r \"echo 'gc='.(ini_get('session.gc_maxlifetime')?:'n/a').PHP_EOL;\"",
        "tail -c 500000 /var/www/apexone/storage/logs/laravel.log | tr -cd '\\11\\12\\15\\40-\\176\\n' | grep -iE 'TokenMismatch|419|CSRF|originate|extension_busy|Page Expired' | tail -n 60",
        "tail -c 200000 /var/www/apexone/storage/logs/laravel.log | tr -cd '\\11\\12\\15\\40-\\176\\n' | tail -n 40",
        "grep -n \"presence\\|keep-alive\\|keepalive\\|caffeine\" /var/www/apexone/routes/web.php | head -30",
    ]
    for cmd in cmds:
        print("====", cmd[:100])
        stdin, stdout, stderr = ssh.exec_command(cmd, timeout=90)
        out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
        print(out.encode("ascii", "replace").decode("ascii")[:4000])

    ssh.close()


if __name__ == "__main__":
    main()
