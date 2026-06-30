#!/usr/bin/env python3
"""Recover server after partial deploy (DB password mismatch)."""
from __future__ import annotations

import os
import re
import shlex
import sys

import paramiko

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
APP = "/var/www/apexone"


def run(ssh: paramiko.SSHClient, command: str, sudo: bool = False) -> str:
    if sudo:
        command = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    print(f"$ {command[:160]}")
    _, stdout, stderr = ssh.exec_command(command, get_pty=True)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"Failed ({code}):\n{out}\n{err}")
    return out


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

    env_out = run(ssh, f"cat {APP}/.env", sudo=True)
    match = re.search(r"^DB_PASSWORD=(.+)$", env_out, re.MULTILINE)
    if not match:
        raise RuntimeError("DB_PASSWORD not found in .env")

    db_pass = match.group(1).strip().strip('"')
    sql = (
        "CREATE DATABASE IF NOT EXISTS apexone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
        "DROP USER IF EXISTS 'apexone'@'localhost'; "
        f"CREATE USER 'apexone'@'localhost' IDENTIFIED BY '{db_pass}'; "
        "GRANT ALL PRIVILEGES ON apexone.* TO 'apexone'@'localhost'; "
        "FLUSH PRIVILEGES;"
    )
    run(ssh, f"mysql -e {shlex.quote(sql)}", sudo=True)

    run(ssh, f"cd {APP} && php artisan migrate --force", sudo=True)
    run(ssh, f"cd {APP} && php artisan db:seed --class=ApexPaymentsWorkspaceSeeder --force", sudo=True)
    run(ssh, f"cd {APP} && php artisan config:cache && php artisan route:cache && php artisan view:cache", sudo=True)
    run(ssh, "systemctl restart apexone-queue", sudo=True)
    run(ssh, "systemctl reload nginx", sudo=True)

    _, out, _ = ssh.exec_command("curl -fsS http://127.0.0.1/up")
    print("HEALTH:", out.read().decode(errors="replace"))
    ssh.close()
    print("Recovery complete.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"RECOVERY FAILED: {exc}", file=sys.stderr)
        raise
