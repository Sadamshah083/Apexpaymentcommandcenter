#!/usr/bin/env python3
"""
Deploy ApexOne Command Center to a fresh Ubuntu server over SSH.
Usage: python deploy/run_deploy.py
"""

from __future__ import annotations

import io
import os
import secrets
import shlex
import string
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
PASSWORD = os.environ.get("DEPLOY_PASSWORD", "btdev")
APP_URL = os.environ.get("APP_URL", f"http://{HOST}")
SKIP_PROVISION = os.environ.get("SKIP_PROVISION", "").lower() in {"1", "true", "yes"}
SKIP_MYSQL = os.environ.get("SKIP_MYSQL", "").lower() in {"1", "true", "yes"}

EXCLUDE_DIRS = {
    ".git",
    "node_modules",
    "vendor",
    "php83",
    ".cursor",
    "terminals",
    "scratch",
}
EXCLUDE_FILES = {
    ".env",
    ".env.ci-test",
    "database/database.sqlite",
    "public/hot",
    "php83.zip",
}


def generate_password(length: int = 24) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def log(msg: str) -> None:
    print(msg, flush=True)


def run(ssh: paramiko.SSHClient, command: str, sudo: bool = False, check: bool = True) -> tuple[int, str, str]:
    if sudo:
        command = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(command)}"
    log(f"$ {command[:180]}{'...' if len(command) > 180 else ''}")
    stdin, stdout, stderr = ssh.exec_command(command, get_pty=True)
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
            if rel.parts and rel.parts[0] in EXCLUDE_DIRS:
                continue
            if rel.name in EXCLUDE_FILES or rel.name.endswith(".zip"):
                continue
            if path.is_dir():
                continue
            tar.add(path, arcname=str(rel).replace("\\", "/"))
    buffer.seek(0)
    return buffer.read()



def upload_bytes(sftp: paramiko.SFTPClient, remote_path: str, payload: bytes) -> None:
    with sftp.file(remote_path, "wb") as remote:
        remote.write(payload)


def main() -> int:
    db_pass = os.environ.get("DB_PASS") or generate_password()
    admin_pass = os.environ.get("ADMIN_PASS") or generate_password(16)

    log(f"Connecting to {USER}@{HOST}...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)
    sftp = ssh.open_sftp()

    log("Building release archive...")
    tarball = build_tarball()
    log(f"Archive size: {len(tarball) / 1024 / 1024:.1f} MB")

    log("Uploading release...")
    upload_bytes(sftp, REMOTE_TAR, tarball)

    if SKIP_PROVISION:
        log("Skipping provisioning (SKIP_PROVISION=1).")
    else:
        log("Provisioning server (may take several minutes on first run)...")
        prov_local = (ROOT / "deploy" / "provision.sh").read_bytes()
        prov_local = prov_local.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
        upload_bytes(sftp, "/tmp/apexone-provision.sh", prov_local)
        run(ssh, "chmod +x /tmp/apexone-provision.sh && bash /tmp/apexone-provision.sh", sudo=True)

    if SKIP_MYSQL:
        log("Skipping MySQL setup (SKIP_MYSQL=1).")
    else:
        log("Preparing MySQL database...")
        sql = (
            f"CREATE DATABASE IF NOT EXISTS apexone CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
            f"DROP USER IF EXISTS 'apexone'@'localhost'; "
            f"CREATE USER 'apexone'@'localhost' IDENTIFIED BY '{db_pass}'; "
            f"GRANT ALL PRIVILEGES ON apexone.* TO 'apexone'@'localhost'; "
            f"FLUSH PRIVILEGES;"
        )
        run(ssh, f"mysql -e {shlex.quote(sql)}", sudo=True)

    log("Extracting application...")
    run(ssh, f"rm -rf {REMOTE_APP}/*", sudo=True)
    run(ssh, f"mkdir -p {REMOTE_APP} && tar -xzf {REMOTE_TAR} -C {REMOTE_APP}", sudo=True)
    run(ssh, f"chown -R www-data:www-data {REMOTE_APP}", sudo=True)

    install_local = (ROOT / "deploy" / "install-app.sh").read_bytes()
    install_local = install_local.replace(b"\r\n", b"\n").replace(b"\r", b"\n")
    upload_bytes(sftp, "/tmp/apexone-install.sh", install_local)
    run(ssh, "chmod +x /tmp/apexone-install.sh", sudo=True)

    log("Installing application...")
    install_cmd = (
        f"DB_PASS={shlex.quote(db_pass)} "
        f"ADMIN_PASS={shlex.quote(admin_pass)} "
        f"APP_URL={shlex.quote(APP_URL)} "
        f"PRESERVE_ENV={shlex.quote(os.environ.get('PRESERVE_ENV', '1' if SKIP_MYSQL else '0'))} "
        f"bash /tmp/apexone-install.sh"
    )
    run(ssh, install_cmd, sudo=True)

    log("Configuring nginx...")
    run(ssh, f"cp {REMOTE_APP}/deploy/nginx-apexone.conf /etc/nginx/sites-available/apexone", sudo=True)
    run(ssh, "rm -f /etc/nginx/sites-enabled/default", sudo=True)
    run(ssh, "ln -sf /etc/nginx/sites-available/apexone /etc/nginx/sites-enabled/apexone", sudo=True)
    run(ssh, "nginx -t", sudo=True)
    run(ssh, "systemctl reload nginx", sudo=True)

    log("Configuring queue workers...")
    run(ssh, f"cp {REMOTE_APP}/deploy/apexone-queue.service /etc/systemd/system/apexone-queue.service", sudo=True)
    run(ssh, "systemctl daemon-reload", sudo=True)
    run(ssh, "systemctl enable apexone-queue", sudo=True)
    run(ssh, "systemctl restart apexone-queue", sudo=True)

    log("Configuring scheduler cron...")
    cron_line = f"* * * * * www-data cd {REMOTE_APP} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"
    run(
        ssh,
        f"grep -q 'artisan schedule:run' /etc/crontab || echo {shlex.quote(cron_line)} >> /etc/crontab",
        sudo=True,
    )

    log("Verifying health endpoint...")
    time.sleep(2)
    _, out, _ = run(ssh, f"curl -fsS {APP_URL}/up", check=False)
    healthy = "ok" in out.lower() or out.strip() != ""

    sftp.close()
    ssh.close()

    log("\n" + "=" * 60)
    log("DEPLOYMENT COMPLETE")
    log("=" * 60)
    log(f"URL:          {APP_URL}")
    log(f"Health:       {APP_URL}/up ({'OK' if healthy else 'check manually'})")
    log(f"Admin login:  {APP_URL}/admin/login")
    log(f"Portal login: {APP_URL}/portal/login")
    log(f"Username:     admin")
    log(f"Password:     {admin_pass}")
    log(f"MySQL DB:     apexone / user apexone")
    log(f"MySQL pass:   {db_pass}")
    log("\nRotate SSH and admin passwords after first login.")
    log("Add API keys (Gemini, OpenRouter, Zoom) in /var/www/apexone/.env on the server.")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"DEPLOY FAILED: {exc}")
        raise
