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
        "sudo -n grep -i 'originate\\|CSRF\\|419\\|extension' /var/www/apexone/storage/logs/laravel.log 2>/dev/null | tail -n 80 || true",
        "sudo -n tail -n 200 /var/www/apexone/storage/logs/laravel.log 2>/dev/null | tr -cd '\\11\\12\\15\\40-\\176' | tail -n 120 || true",
        "ls -la /var/www/apexone/storage/logs/ | tail -n 20",
        "grep -n SESSION_LIFETIME /var/www/apexone/.env 2>/dev/null || true",
        "grep -n lifetime /var/www/apexone/config/session.php | head -n 10",
    ]
    for cmd in cmds:
        print("====", cmd[:90])
        stdin, stdout, stderr = ssh.exec_command(cmd, timeout=60)
        out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
        print(out.encode("ascii", "replace").decode("ascii")[:3500])

    ssh.close()


if __name__ == "__main__":
    main()
