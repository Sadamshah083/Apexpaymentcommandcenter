#!/usr/bin/env python3
"""Safe production update: upload release tarball, migrate, rebuild assets (preserves .env and data)."""

from __future__ import annotations

import io
import os
import shlex
import sys
import tarfile
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
REMOTE_APP = "/var/www/apexone"
REMOTE_TAR = "/tmp/apexone-release.tar.gz"

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "")
APP_URL = os.environ.get("APP_URL", "https://crm.apexonepayments.com")

EXCLUDE_DIRS = {".git", "node_modules", "vendor", "php83", ".cursor", "terminals", "scratch"}
EXCLUDE_FILES = {".env", ".env.ci-test", "database/database.sqlite", "public/hot"}


def log(msg: str) -> None:
    print(msg, flush=True)


def run(ssh: paramiko.SSHClient, command: str, sudo: bool = False, check: bool = True) -> tuple[int, str, str]:
    if sudo:
        command = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    log(f"$ {command[:200]}{'...' if len(command) > 200 else ''}")
    _, stdout, stderr = ssh.exec_command(command, get_pty=True)
    exit_code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if check and exit_code != 0:
        raise RuntimeError(f"Command failed ({exit_code}):\n{out}\n{err}")
    return exit_code, out, err


def build_tarball() -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for path in ROOT.rglob("*"):
            rel = path.relative_to(ROOT)
            if set(rel.parts) & EXCLUDE_DIRS:
                continue
            if rel.name in EXCLUDE_FILES:
                continue
            if path.is_dir():
                continue
            tar.add(path, arcname=str(rel).replace("\\", "/"))
    buffer.seek(0)
    return buffer.read()


def main() -> int:
    log(f"Connecting to {USER}@{HOST}...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    sftp = ssh.open_sftp()

    log("Building release archive...")
    tarball = build_tarball()
    log(f"Archive size: {len(tarball) / 1024 / 1024:.1f} MB")

    log("Uploading release...")
    with sftp.file(REMOTE_TAR, "wb") as remote:
        remote.write(tarball)

    log("Backing up .env and extracting release...")
    run(ssh, f"test -f {REMOTE_APP}/.env && cp {REMOTE_APP}/.env /tmp/apexone.env.bak || true", sudo=True)
    run(ssh, f"tar -xzf {REMOTE_TAR} -C {REMOTE_APP}", sudo=True)
    run(ssh, f"test -f {REMOTE_APP}/public/index.php && test -f {REMOTE_APP}/artisan", sudo=True)
    run(ssh, f"test -f /tmp/apexone.env.bak && cp /tmp/apexone.env.bak {REMOTE_APP}/.env || true", sudo=True)
    run(ssh, f"chown -R www-data:www-data {REMOTE_APP}", sudo=True)

    update_cmds = (
        f"cd {REMOTE_APP} && "
        "export COMPOSER_ALLOW_SUPERUSER=1 && "
        "composer install --no-dev --optimize-autoloader --no-interaction && "
        "npm ci --ignore-scripts && "
        "npm run build && "
        "rm -rf node_modules && "
        "php artisan migrate --force && "
        "php artisan config:cache && "
        "php artisan route:cache && "
        "php artisan view:cache && "
        "rm -f public/hot && "
        f"chown -R www-data:www-data storage bootstrap/cache public/build .env"
    )
    log("Installing dependencies, building assets, running migrations...")
    run(ssh, update_cmds, sudo=True)

    log("Restarting services...")
    run(ssh, "systemctl restart apexone-queue php8.3-fpm", sudo=True)
    run(ssh, "systemctl reload nginx", sudo=True)

    time.sleep(2)
    _, out, _ = run(ssh, f"curl -fsS {APP_URL}/up", check=False)
    healthy = "ok" in out.lower() or out.strip() != ""

    sftp.close()
    ssh.close()

    log("\n" + "=" * 60)
    log("UPDATE COMPLETE")
    log("=" * 60)
    log(f"URL:    {APP_URL}")
    log(f"Health: {APP_URL}/up ({'OK' if healthy else 'check manually'})")
    log(f"Commit: 7c34476 on main")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"UPDATE FAILED: {exc}")
        raise
