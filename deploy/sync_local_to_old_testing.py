#!/usr/bin/env python3
"""
Push LOCAL workspace code → OLD testing server (when NEW prod SSH is down).

Does NOT touch production / NEW server / DNS.
OLD remains testing-only at http://203.215.160.44
"""

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

OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}
APP = "/var/www/apexone"
TEST_APP_URL = f"http://{OLD['host']}"
NEW_HOST = "203.215.161.236"

INCLUDE_ROOT_FILES = {
    "artisan",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "vite.config.js",
    "phpunit.xml",
    "tailwind.config.js",
    "postcss.config.js",
}
INCLUDE_DIRS = (
    "app",
    "bootstrap",
    "config",
    "database",
    "resources",
    "routes",
    "services",
    "public",
    "tests",
)


def log(msg: str) -> None:
    print(msg, flush=True)


def connect() -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(OLD["host"], username=OLD["user"], password=OLD["password"], timeout=45)
    return ssh


def sudo(ssh: paramiko.SSHClient, cmd: str, timeout: int = 900) -> str:
    full = f"echo {shlex.quote(OLD['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = (stdout.read() + stderr.read()).decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"sudo failed ({code}):\n{out[-4000:]}")
    return out


def should_skip(rel: str) -> bool:
    rel = rel.replace("\\", "/")
    parts = rel.split("/")
    if "node_modules" in parts or "vendor" in parts:
        return True
    if rel.startswith("public/build/") or rel == "public/build":
        return True
    if parts[0].startswith(".") and rel not in {".env.example"}:
        return True
    return False


def build_tarball() -> bytes:
    buf = io.BytesIO()
    count = 0
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        for name in INCLUDE_ROOT_FILES:
            path = ROOT / name
            if path.is_file():
                tar.add(path, arcname=name)
                count += 1
        for dirname in INCLUDE_DIRS:
            base = ROOT / dirname
            if not base.exists():
                continue
            for path in base.rglob("*"):
                if not path.is_file():
                    continue
                rel = path.relative_to(ROOT).as_posix()
                if should_skip(rel):
                    continue
                tar.add(path, arcname=rel)
                count += 1
    log(f"Packed {count} files ({buf.tell() / 1024 / 1024:.1f} MB)")
    return buf.getvalue()


def main() -> int:
    log("=" * 64)
    log("LOCAL → OLD testing sync (code)")
    log(f"OLD testing: {TEST_APP_URL}")
    log(f"NEW production host {NEW_HOST} / domain untouched")
    log("=" * 64)

    blob = build_tarball()
    ssh = connect()
    try:
        remote_tar = "/tmp/apexone-local-sync.tgz"
        with ssh.open_sftp() as sftp:
            sftp.putfo(io.BytesIO(blob), remote_tar)

        out = sudo(
            ssh,
            f"""
set -e
mkdir -p {APP}
tar -xzf {remote_tar} -C {APP}
rm -f {remote_tar}

# Keep testing identity
if [ -f {APP}/.env ]; then
  sed -i 's|^APP_URL=.*|APP_URL={TEST_APP_URL}|' {APP}/.env || true
  grep -q '^APP_URL=' {APP}/.env || echo 'APP_URL={TEST_APP_URL}' >> {APP}/.env
  sed -i 's|^APP_ENV=.*|APP_ENV=local|' {APP}/.env || true
  grep -q '^APEX_SERVER_ROLE=' {APP}/.env && sed -i 's|^APEX_SERVER_ROLE=.*|APEX_SERVER_ROLE=testing|' {APP}/.env || echo 'APEX_SERVER_ROLE=testing' >> {APP}/.env
  grep -q '^APEX_PRODUCTION_HOST=' {APP}/.env && sed -i 's|^APEX_PRODUCTION_HOST=.*|APEX_PRODUCTION_HOST={NEW_HOST}|' {APP}/.env || echo 'APEX_PRODUCTION_HOST={NEW_HOST}' >> {APP}/.env
fi

cat > {APP}/TESTING_SERVER.txt <<'EOF'
THIS IS THE TESTING SERVER (old IP).
Production domain crm.apexonepayments.com stays on the NEW server.
EOF

chown -R www-data:www-data {APP}
cd {APP}

# composer if vendor missing
if [ ! -d vendor ] && command -v composer >/dev/null; then
  sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction || true
fi

# vite build if node available
if command -v npm >/dev/null; then
  sudo -u www-data npm ci --omit=dev 2>/dev/null || sudo -u www-data npm install --omit=dev || true
  sudo -u www-data npm run build || true
fi

sudo -u www-data php artisan view:clear || true
sudo -u www-data php artisan config:clear || true
sudo -u www-data php artisan cache:clear || true
systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
systemctl reload nginx 2>/dev/null || true

curl -s -o /dev/null -w 'up=%{{http_code}}\\n' http://127.0.0.1/up || true
curl -s -o /dev/null -w 'admin=%{{http_code}}\\n' http://127.0.0.1/admin/login || true
grep -E '^(APP_URL|APEX_SERVER_ROLE)=' {APP}/.env || true
echo LOCAL_SYNC_OK
""",
            timeout=1800,
        )
        log(out[-5000:])
        if "LOCAL_SYNC_OK" not in out:
            raise SystemExit("Local→old sync failed")
    finally:
        ssh.close()

    log("\nDone. Test at:")
    log(f"  {TEST_APP_URL}/admin/login")
    log("Production domain remains on NEW server.")
    return 0


if __name__ == "__main__":
    t0 = time.time()
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"FATAL: {exc}")
        raise
    finally:
        log(f"Elapsed {int(time.time() - t0)}s")
