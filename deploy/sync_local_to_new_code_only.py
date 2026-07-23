#!/usr/bin/env python3
"""
Push LOCAL workspace code → NEW production server (UI/UX parity).

RULES:
- Code + built assets only.
- Does NOT run migrate / seed / db:wipe / mysqldump import.
- Does NOT modify MySQL data or schema.
- Does NOT overwrite NEW .env (APP_URL, DB_*, keys stay as-is).
- Production domain crm.apexonepayments.com stays on NEW.
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

NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}
APP = "/var/www/apexone"
PROD_DOMAIN = "crm.apexonepayments.com"

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
    "database",  # files only — never executed
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
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=45)
    return ssh


def sudo(ssh: paramiko.SSHClient, cmd: str, timeout: int = 1800) -> str:
    full = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = (stdout.read() + stderr.read()).decode(errors="replace")
    if code != 0:
        raise RuntimeError(f"sudo failed ({code}):\n{out[-5000:]}")
    return out


def should_skip(rel: str) -> bool:
    rel = rel.replace("\\", "/")
    parts = rel.split("/")
    if "node_modules" in parts or "vendor" in parts:
        return True
    # Prefer local public/build when present; pack separately below.
    if rel.startswith("public/build/") or rel == "public/build":
        return True
    if parts[0].startswith(".") and rel not in {".env.example"}:
        return True
    # Never ship local env or sqlite into production.
    if rel in {".env", "database/database.sqlite"}:
        return True
    return False


def build_code_tarball() -> bytes:
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
    log(f"Packed {count} code files ({buf.tell() / 1024 / 1024:.1f} MB)")
    return buf.getvalue()


def build_assets_tarball() -> bytes | None:
    build = ROOT / "public" / "build"
    if not build.exists():
        return None
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        tar.add(build, arcname="public/build")
    log(f"Packed public/build ({buf.tell() / 1024 / 1024:.1f} MB)")
    return buf.getvalue()


def main() -> int:
    log("=" * 64)
    log("LOCAL → NEW production sync (CODE + UI ONLY)")
    log(f"Target: {NEW['user']}@{NEW['host']}  ({PROD_DOMAIN})")
    log("DB: UNTOUCHED (no migrate / seed / dump / import)")
    log(".env: PRESERVED")
    log("=" * 64)

    code_blob = build_code_tarball()
    assets_blob = build_assets_tarball()

    ssh = connect()
    try:
        # Snapshot DB fingerprint BEFORE deploy (prove no change).
        before = sudo(
            ssh,
            f"""
set -e
cd {APP}
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$rows = DB::select("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()");
$mig = DB::table("migrations")->count();
$users = Schema::hasTable("users") ? DB::table("users")->count() : -1;
echo "tables=".$rows[0]->c." migrations=".$mig." users=".$users."\\n";
'
""",
            timeout=120,
        )
        log("DB before: " + before.strip().splitlines()[-1])

        remote_code = "/tmp/apexone-local-to-new-code.tgz"
        remote_assets = "/tmp/apexone-local-to-new-assets.tgz"
        with ssh.open_sftp() as sftp:
            sftp.putfo(io.BytesIO(code_blob), remote_code)
            if assets_blob is not None:
                sftp.putfo(io.BytesIO(assets_blob), remote_assets)

        extract_assets = (
            f"""
if [ -f {remote_assets} ]; then
  tar -xzf {remote_assets} -C {APP}
  rm -f {remote_assets}
fi
"""
            if assets_blob is not None
            else ""
        )

        out = sudo(
            ssh,
            f"""
set -e
# Preserve production .env
cp -a {APP}/.env /tmp/apexone-env.preserve

tar -xzf {remote_code} -C {APP}
rm -f {remote_code}
{extract_assets}

# Restore .env no matter what was in the tarball
cp -a /tmp/apexone-env.preserve {APP}/.env
rm -f /tmp/apexone-env.preserve

chown -R www-data:www-data {APP}/app {APP}/bootstrap {APP}/config {APP}/database \\
  {APP}/resources {APP}/routes {APP}/services {APP}/public {APP}/tests \\
  {APP}/artisan {APP}/composer.json {APP}/composer.lock {APP}/package.json \\
  {APP}/package-lock.json {APP}/vite.config.js 2>/dev/null || true
chown -R www-data:www-data {APP}/public/build 2>/dev/null || true

cd {APP}

# Optional: refresh PHP deps if lock drifted — still no DB ops
if command -v composer >/dev/null; then
  sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction || true
fi

# Prefer uploaded build; rebuild only if manifest missing
if [ ! -f public/build/manifest.json ] && command -v npm >/dev/null; then
  sudo -u www-data npm ci --omit=dev 2>/dev/null || sudo -u www-data npm install --omit=dev || true
  sudo -u www-data npm run build || true
  chown -R www-data:www-data public/build
fi

# Cache clears only — NEVER migrate / seed / db:*
sudo -u www-data php artisan view:clear || true
sudo -u www-data php artisan config:clear || true
sudo -u www-data php artisan route:clear || true
sudo -u www-data php artisan cache:clear || true

systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
systemctl reload nginx 2>/dev/null || true

# Verify assigned-leads route + UI markers
sudo -u www-data php artisan route:list --path=assigned-leads 2>&1 | head -5 || true
grep -n "assigned-leads" routes/web.php | head -2 || true
grep -n "import-workflows-page--assigned" resources/css/app.css | head -2 || true
test -f public/build/manifest.json && echo BUILD_OK || echo BUILD_MISSING

curl -s -o /dev/null -w 'up=%{{http_code}}\\n' https://127.0.0.1/up -k || \\
  curl -s -o /dev/null -w 'up=%{{http_code}}\\n' http://127.0.0.1/up || true

# DB fingerprint AFTER — must match before
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$rows = DB::select("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()");
$mig = DB::table("migrations")->count();
$users = Schema::hasTable("users") ? DB::table("users")->count() : -1;
echo "tables=".$rows[0]->c." migrations=".$mig." users=".$users."\\n";
'

echo LOCAL_TO_NEW_CODE_OK
""",
            timeout=1800,
        )
        log(out[-6000:])
        if "LOCAL_TO_NEW_CODE_OK" not in out:
            raise SystemExit("Local→NEW sync failed")

        # Compare fingerprints
        before_line = [ln for ln in before.splitlines() if ln.startswith("tables=")][-1]
        after_lines = [ln for ln in out.splitlines() if ln.startswith("tables=")]
        after_line = after_lines[-1] if after_lines else ""
        if before_line and after_line and before_line != after_line:
            raise SystemExit(f"DB fingerprint changed! before={before_line} after={after_line}")
        log(f"DB unchanged: {before_line}")
    finally:
        ssh.close()

    log("\nDone. Live UI:")
    log(f"  https://{PROD_DOMAIN}/admin/assigned-leads")
    log(f"  https://{PROD_DOMAIN}/admin/workflows")
    log("Database was not migrated, seeded, or modified.")
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
