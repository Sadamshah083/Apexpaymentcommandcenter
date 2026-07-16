#!/usr/bin/env python3
"""Probe DB + nginx + queue workers on old server."""

from __future__ import annotations

import shlex

import paramiko

HOST = "203.215.160.44"
USER = "issac"
PW = "SadamShah123"


def sudo_run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 180) -> str:
    full = f"echo {shlex.quote(PW)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    lines = [ln for ln in (out + err).splitlines() if "password for" not in ln.lower()]
    return "\n".join(lines).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)

    print("===== nginx conf =====")
    print(sudo_run(ssh, "cat /etc/nginx/sites-available/apexone"))

    print("\n===== db keys (masked) =====")
    print(
        sudo_run(
            ssh,
            "grep -E '^(DB_|REDIS_|QUEUE_|CACHE_|SESSION_|MAIL_|BROADCAST_)' /var/www/apexone/.env "
            "| sed -E 's/(PASSWORD|SECRET|KEY)=.*/\\1=***/'",
        )
    )

    print("\n===== db size =====")
    print(
        sudo_run(
            ssh,
            "DB=$(grep ^DB_DATABASE= /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'); "
            "USER=$(grep ^DB_USERNAME= /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'); "
            "PASS=$(grep ^DB_PASSWORD= /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'); "
            "HOST=$(grep ^DB_HOST= /var/www/apexone/.env | cut -d= -f2- | tr -d '\"'); "
            "echo DB=$DB USER=$USER HOST=$HOST; "
            "mysql -h\"$HOST\" -u\"$USER\" -p\"$PASS\" -e \"SELECT table_schema AS db, "
            "ROUND(SUM(data_length+index_length)/1024/1024,1) AS MB FROM information_schema.tables "
            "WHERE table_schema='$DB' GROUP BY table_schema;\"",
        )
    )

    print("\n===== supervisor =====")
    print(sudo_run(ssh, "ls -la /etc/supervisor/conf.d; echo ---; cat /etc/supervisor/conf.d/* 2>/dev/null | head -120"))

    print("\n===== php modules =====")
    print(sudo_run(ssh, "php -m | tr '\\n' ' '; echo; dpkg -l | grep -E 'php8.3|nginx|mysql-server' | awk '{print $2}' | head -60"))

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
