#!/usr/bin/env python3
"""Probe production (old) server for migrate prep."""

from __future__ import annotations

import shlex
import sys

import paramiko

HOST = "203.215.160.44"
USER = "issac"
PW = "SadamShah123"


def sudo_run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 180) -> str:
    full = f"echo {shlex.quote(PW)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    # strip sudo password prompt noise
    lines = [ln for ln in (out + err).splitlines() if "password for" not in ln.lower()]
    return "\n".join(lines).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PW, timeout=30)

    cmds = {
        "app_ls": "ls -la /var/www/apexone | head -40",
        "nginx_sites": "ls -la /etc/nginx/sites-enabled; echo ---; ls -la /etc/nginx/sites-available",
        "server_names": "grep -R \"server_name\" /etc/nginx/sites-available /etc/nginx/sites-enabled 2>/dev/null | head -60",
        "php_fpm": "ls /run/php; systemctl is-active php8.3-fpm nginx mysql 2>/dev/null; php -v | head -1",
        "env_db": "grep -E '^(APP_URL|DB_|APP_ENV|APP_KEY)=' /var/www/apexone/.env | sed 's/DB_PASSWORD=.*/DB_PASSWORD=***/'",
        "ssl": "ls -la /etc/letsencrypt/live 2>/dev/null; ls /etc/nginx/ssl 2>/dev/null | head",
        "disk_db": "du -sh /var/www/apexone /var/lib/mysql 2>/dev/null; df -h / | tail -1",
        "supervisor_pm2": "systemctl is-active supervisor 2>/dev/null; pm2 list 2>/dev/null | head -20; ls /etc/supervisor/conf.d 2>/dev/null",
    }
    for name, cmd in cmds.items():
        print(f"\n===== {name} =====")
        print(sudo_run(ssh, cmd))

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
