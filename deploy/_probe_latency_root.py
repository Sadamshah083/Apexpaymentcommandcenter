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
        "echo balitech1 | sudo -S grep -E '^DB_|^SESSION_|^CACHE_|^QUEUE_|^MORPHEUS_' /var/www/apexone/.env 2>/dev/null | sed 's/=.*/=***/' | head -40",
        "echo balitech1 | sudo -S grep -E '^DB_CONNECTION|^DB_DATABASE|^SESSION_DRIVER|^CACHE_STORE|^QUEUE_CONNECTION' /var/www/apexone/.env 2>/dev/null | head -20",
        "ls -lah /var/www/apexone/database/*.sqlite 2>/dev/null; ls -lah /var/www/apexone/storage/framework/sessions 2>/dev/null | head -5",
        "ps aux | grep -E 'php-fpm|queue:work|nginx' | grep -v grep | head -30",
        "echo balitech1 | sudo -S php /var/www/apexone/artisan tinker --execute=\"echo 'db='.config('database.default').' session='.config('session.driver').' cache='.config('cache.default').' queue='.config('queue.default');\" 2>/dev/null | tail -5",
    ]
    for cmd in cmds:
        print("====", cmd[:100])
        stdin, stdout, stderr = ssh.exec_command(cmd, timeout=60)
        out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
        print(out.encode("ascii", "replace").decode("ascii")[:3500])

    ssh.close()


if __name__ == "__main__":
    main()
