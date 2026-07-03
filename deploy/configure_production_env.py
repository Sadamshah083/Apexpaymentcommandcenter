#!/usr/bin/env python3
"""Sync local .env integration settings to production (preserves server DB, APP_KEY, admin creds)."""

from __future__ import annotations

import os
import re
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch

ENV_PATH = f"{REMOTE_APP}/.env"
LOCAL_ENV = ROOT / ".env"

# Never overwrite these on the server — production-specific or generated at install.
PRESERVE_KEYS = {
    "APP_KEY",
    "DB_CONNECTION",
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "PRODUCTION_ADMIN_USER",
    "PRODUCTION_ADMIN_PASSWORD",
    "PRODUCTION_ADMIN_EMAIL",
}

# Always enforce production-safe values.
FORCE_VALUES = {
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "APP_URL": os.environ.get("APP_URL", "http://203.215.160.44"),
    "APP_ALLOW_INSECURE_HTTP_IN_LOCAL": "false",
    "LOG_LEVEL": "warning",
    "SESSION_DRIVER": "database",
    "QUEUE_CONNECTION": "database",
    "CACHE_STORE": "database",
    "MAIL_MAILER": "log",
}


def parse_env(text: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for line in text.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, value = stripped.split("=", 1)
        values[key.strip()] = value.strip()
    return values


def format_env(
    remote_text: str,
    local: dict[str, str],
    remote: dict[str, str],
) -> str:
    merged = dict(remote)
    added: list[str] = []
    updated: list[str] = []

    for key, value in local.items():
        if key in PRESERVE_KEYS:
            continue
        if value == "" and key not in remote:
            continue
        if key in FORCE_VALUES:
            continue
        if key not in remote:
            merged[key] = value
            added.append(key)
        elif value and remote.get(key, "") != value:
            merged[key] = value
            updated.append(key)

    for key, value in FORCE_VALUES.items():
        if remote.get(key) != value:
            merged[key] = value
            updated.append(key)

    if "PRODUCTION_ADMIN_PASSWORD" not in merged:
        merged["PRODUCTION_ADMIN_PASSWORD"] = "rwlt4NBN2MtIbQ0A"
        added.append("PRODUCTION_ADMIN_PASSWORD")
    if "PRODUCTION_ADMIN_USER" not in merged:
        merged["PRODUCTION_ADMIN_USER"] = "admin"
        added.append("PRODUCTION_ADMIN_USER")
    if "PRODUCTION_ADMIN_EMAIL" not in merged:
        merged["PRODUCTION_ADMIN_EMAIL"] = "admin@apexone.local"
        added.append("PRODUCTION_ADMIN_EMAIL")

    lines: list[str] = []
    used: set[str] = set()

    for line in remote_text.splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            lines.append(line)
            continue
        key = stripped.split("=", 1)[0].strip()
        if key in used:
            continue
        used.add(key)
        if key in merged:
            lines.append(f"{key}={merged[key]}")
            del merged[key]
        else:
            lines.append(line)

    if merged:
        lines.append("")
        lines.append("# Synced from local development .env")
        for key in sorted(merged):
            lines.append(f"{key}={merged[key]}")

    print(f"Added {len(added)} key(s): {', '.join(sorted(added)) or 'none'}")
    print(f"Updated {len(updated)} key(s): {', '.join(sorted(set(updated))) or 'none'}")
    return "\n".join(lines).rstrip() + "\n"


def sudo_cat(ssh: paramiko.SSHClient, path: str) -> str:
    password = os.environ.get("DEPLOY_PASSWORD", "btdev")
    cmd = f"echo {shlex.quote(password)} | sudo -S cat {shlex.quote(path)}"
    _, stdout, stderr = ssh.exec_command(cmd)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"Failed to read {path}: {out}\n{stderr.read().decode(errors='replace')}")
    return out


def sudo_write(ssh: paramiko.SSHClient, path: str, content: str) -> None:
    tmp = "/tmp/apexone.env.configured"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(content)
    sftp.close()
    sudo_run_batch(ssh, [
        f"cp {tmp} {path}",
        f"chown www-data:www-data {path}",
        f"chmod 640 {path}",
    ])


def main() -> int:
    if not LOCAL_ENV.exists():
        raise SystemExit(f"Local env missing: {LOCAL_ENV}")

    local = parse_env(LOCAL_ENV.read_text(encoding="utf-8"))
    ssh = connect()
    remote_text = sudo_cat(ssh, ENV_PATH)
    remote = parse_env(remote_text)

    merged_text = format_env(remote_text, local, remote)
    sudo_write(ssh, ENV_PATH, merged_text)

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
        "systemctl restart apexone-queue php8.3-fpm",
    ])
    ssh.close()
    print("Production .env configured and services restarted.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
