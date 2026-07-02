#!/usr/bin/env python3
"""Backup current server state, redeploy app, and switch database to SQLite."""

from __future__ import annotations

import io
import os
import secrets
import shlex
import string
import sys
import tarfile
import time
from datetime import datetime, timezone
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
REMOTE_APP = "/var/www/apexone"
REMOTE_TAR = "/tmp/apexone-release.tar.gz"
SNAPSHOT_DIR = "/var/backups/apexone"

HOST = os.environ.get("DEPLOY_HOST", "203.215.160.44")
USER = os.environ.get("DEPLOY_USER", "issac")
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
APP_URL = os.environ.get("APP_URL", f"http://{HOST}")

EXCLUDE_DIRS = {".git", "node_modules", "vendor", "php83", ".cursor", "terminals", "scratch"}
EXCLUDE_FILES = {".env", ".env.ci-test", "database/database.sqlite", "public/hot", "php83.zip"}


def log(msg: str) -> None:
    print(msg, flush=True)


def generate_password(length: int = 16) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def run(ssh: paramiko.SSHClient, command: str, sudo: bool = False, check: bool = True) -> tuple[int, str, str]:
    if sudo:
        command = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    log(f"$ {command[:200]}{'...' if len(command) > 200 else ''}")
    _, stdout, stderr = ssh.exec_command(command, get_pty=True)
    code = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors="replace")
    err = stderr.read().decode(errors="replace")
    if check and code != 0:
        raise RuntimeError(f"Command failed ({code}):\n{out}\n{err}")
    return code, out, err


def build_tarball() -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as tar:
        for path in ROOT.rglob("*"):
            rel = path.relative_to(ROOT)
            if rel.parts and rel.parts[0] in EXCLUDE_DIRS:
                continue
            if rel.name in EXCLUDE_FILES or rel.name.endswith(".zip"):
                continue
            if path.is_dir():
                continue
            tar.add(path, arcname=str(rel).replace("\\", "/"))
    buffer.seek(0)
    return buffer.read()


def sqlite_env(admin_pass: str) -> str:
    return f"""APP_NAME="ApexOne Command Center"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL={APP_URL}

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/apexone/database/database.sqlite

SESSION_DRIVER=file
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
QUEUE_WORKERS=1

CACHE_STORE=database

MAIL_MAILER=log

VITE_APP_NAME="ApexOne Command Center"

PRODUCTION_ADMIN_USER=admin
PRODUCTION_ADMIN_PASSWORD={admin_pass}
PRODUCTION_ADMIN_EMAIL=admin@apexone.local

OPENROUTER_API_KEY=
OPENROUTER_MODEL=openai/gpt-oss-20b:free
OPENROUTER_FALLBACK_MODELS=openrouter/free,meta-llama/llama-3.3-70b-instruct:free
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-pro
GEMINI_FALLBACK_MODELS=gemini-2.5-flash,gemini-2.0-flash
"""


def main() -> int:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    admin_pass = os.environ.get("ADMIN_PASS") or generate_password()

    log(f"Connecting to {USER}@{HOST}...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    sftp = ssh.open_sftp()

    log("Creating server snapshot backup...")
    run(ssh, f"mkdir -p {SNAPSHOT_DIR}", sudo=True)
    snapshot_cmds = [
        f"test -f {REMOTE_APP}/.env && cp {REMOTE_APP}/.env {SNAPSHOT_DIR}/env-{stamp}.bak || true",
        f"test -f {REMOTE_APP}/database/database.sqlite && cp {REMOTE_APP}/database/database.sqlite {SNAPSHOT_DIR}/sqlite-{stamp}.bak || true",
        f"mysqldump apexone > {SNAPSHOT_DIR}/mysql-apexone-{stamp}.sql 2>/dev/null || true",
        f"tar -czf {SNAPSHOT_DIR}/apexone-app-{stamp}.tar.gz -C /var/www apexone 2>/dev/null || true",
    ]
    for cmd in snapshot_cmds:
        run(ssh, cmd, sudo=True, check=False)
    _, out, _ = run(ssh, f"ls -lh {SNAPSHOT_DIR} | tail -10", sudo=True, check=False)
    log(out)

    log("Ensuring PHP SQLite extension is installed...")
    run(
        ssh,
        "apt-get update -y && apt-get install -y php8.3-sqlite3",
        sudo=True,
        check=False,
    )

    log("Building and uploading release archive...")
    tarball = build_tarball()
    log(f"Archive size: {len(tarball) / 1024 / 1024:.1f} MB")
    run(ssh, f"rm -f {REMOTE_TAR}", check=False)
    with sftp.file(REMOTE_TAR, "wb") as remote:
        remote.write(tarball)

    log("Extracting application...")
    run(ssh, f"rm -rf {REMOTE_APP}/*", sudo=True)
    run(ssh, f"mkdir -p {REMOTE_APP} && tar -xzf {REMOTE_TAR} -C {REMOTE_APP}", sudo=True)

    log("Writing SQLite .env...")
    env_path = f"/tmp/apexone-env-{stamp}"
    with sftp.file(env_path, "w") as remote:
        remote.write(sqlite_env(admin_pass))
    run(ssh, f"mv {env_path} {REMOTE_APP}/.env && chown www-data:www-data {REMOTE_APP}/.env && chmod 640 {REMOTE_APP}/.env", sudo=True)

    install_cmds = [
        f"cd {REMOTE_APP} && chown -R www-data:www-data {REMOTE_APP}",
        f"cd {REMOTE_APP} && touch database/database.sqlite && chown www-data:www-data database/database.sqlite && chmod 664 database/database.sqlite",
        f"cd {REMOTE_APP} && export COMPOSER_ALLOW_SUPERUSER=1 && composer install --no-dev --optimize-autoloader --no-interaction",
        f"cd {REMOTE_APP} && npm ci --ignore-scripts && npm run build && rm -rf node_modules",
        f"cd {REMOTE_APP} && php artisan key:generate --force",
        f"cd {REMOTE_APP} && php artisan migrate:fresh --seed --force",
        f"cd {REMOTE_APP} && php artisan db:seed --class=ApexPaymentsWorkspaceSeeder --force",
        f"cd {REMOTE_APP} && php scripts/production-bootstrap.php",
        f"cd {REMOTE_APP} && php artisan storage:link || true",
        f"cd {REMOTE_APP} && php artisan config:cache && php artisan route:cache && php artisan view:cache",
        f"chown -R www-data:www-data {REMOTE_APP}/storage {REMOTE_APP}/bootstrap/cache {REMOTE_APP}/public/build {REMOTE_APP}/.env {REMOTE_APP}/database/database.sqlite",
        f"chmod -R ug+rwx {REMOTE_APP}/storage {REMOTE_APP}/bootstrap/cache",
        f"rm -f {REMOTE_APP}/public/hot",
    ]
    for cmd in install_cmds:
        run(ssh, cmd, sudo=True)

    log("Configuring nginx and services...")
    run(ssh, f"cp {REMOTE_APP}/deploy/nginx-apexone.conf /etc/nginx/sites-available/apexone", sudo=True)
    run(ssh, "rm -f /etc/nginx/sites-enabled/default", sudo=True)
    run(ssh, "ln -sf /etc/nginx/sites-available/apexone /etc/nginx/sites-enabled/apexone", sudo=True)
    run(ssh, "nginx -t", sudo=True)
    run(ssh, "systemctl reload nginx", sudo=True)
    run(ssh, f"cp {REMOTE_APP}/deploy/apexone-queue.service /etc/systemd/system/apexone-queue.service", sudo=True)
    run(ssh, "systemctl daemon-reload && systemctl enable apexone-queue && systemctl restart apexone-queue", sudo=True)
    cron_line = f"* * * * * www-data cd {REMOTE_APP} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"
    run(
        ssh,
        f"grep -q 'artisan schedule:run' /etc/crontab || echo {shlex.quote(cron_line)} >> /etc/crontab",
        sudo=True,
    )

    time.sleep(2)
    _, health, _ = run(ssh, "curl -fsS http://127.0.0.1/up", check=False)
    _, admin_code, _ = run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/login", check=False)
    _, sqlite_check, _ = run(ssh, f"ls -lh {REMOTE_APP}/database/database.sqlite", sudo=True, check=False)

    sftp.close()
    ssh.close()

    log("\n" + "=" * 60)
    log("SQLITE DEPLOYMENT COMPLETE")
    log("=" * 60)
    log(f"URL:          {APP_URL}")
    log(f"Health:       {health.strip()}")
    log(f"Admin login:  {APP_URL}/admin/login (HTTP {admin_code.strip()})")
    log(f"Portal login: {APP_URL}/portal/login")
    log(f"Username:     admin")
    log(f"Password:     {admin_pass}")
    log(f"Database:     SQLite at {REMOTE_APP}/database/database.sqlite")
    log(f"Snapshot:     {SNAPSHOT_DIR}/ (stamp {stamp})")
    log(sqlite_check.strip())
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"DEPLOY FAILED: {exc}")
        raise
