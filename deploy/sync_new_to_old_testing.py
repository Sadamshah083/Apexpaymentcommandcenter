#!/usr/bin/env python3
"""
Sync NEW production server → OLD testing server.

RULES (do not change):
- Production domain crm.apexonepayments.com STAYS on NEW server (203.215.161.236).
- OLD server (203.215.160.44) is TESTING ONLY — access by IP, not the live domain.
- DNS is never modified by this script.
"""

from __future__ import annotations

import os
import shlex
import sys
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
STAGING = ROOT / "deploy" / "_sync_staging"

# NEW = production (domain lives here)
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}

# OLD = testing only (no production domain)
OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}

APP_REMOTE = "/var/www/apexone"
PROD_DOMAIN = "crm.apexonepayments.com"
# Testing URL — IP only. Never point APP_URL at the production domain on OLD.
TEST_APP_URL = f"http://{OLD['host']}"


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=45)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, cmd: str, timeout: int = 900) -> tuple[int, str]:
    full = f"echo {shlex.quote(password)} | sudo -S -p '' bash -lc {shlex.quote(cmd)}"
    _, stdout, stderr = ssh.exec_command(full, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = (stdout.read() + stderr.read()).decode(errors="replace")
    return code, out


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 300) -> tuple[int, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    code = stdout.channel.recv_exit_status()
    out = (stdout.read() + stderr.read()).decode(errors="replace")
    return code, out


def log(msg: str) -> None:
    print(msg, flush=True)


def sftp_get(ssh: paramiko.SSHClient, remote: str, local: Path) -> None:
    local.parent.mkdir(parents=True, exist_ok=True)
    with ssh.open_sftp() as sftp:
        sftp.get(remote, str(local))


def sftp_put(ssh: paramiko.SSHClient, local: Path, remote: str) -> None:
    with ssh.open_sftp() as sftp:
        sftp.put(str(local), remote)


def phase_package_new() -> dict:
    log("\n=== PHASE 1: Package app + DB from NEW (production) ===")
    log(f"Source: {NEW['host']}  (domain {PROD_DOMAIN} stays here)")
    ssh = connect(NEW)
    pw = NEW["password"]

    code, env_out = sudo(
        ssh,
        pw,
        f"grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' {APP_REMOTE}/.env",
    )
    creds: dict[str, str] = {}
    for line in env_out.splitlines():
        if "=" in line and not line.strip().startswith("["):
            k, _, v = line.partition("=")
            creds[k.strip()] = v.strip().strip('"').strip("'")

    db = creds.get("DB_DATABASE", "apexone")
    db_user = creds.get("DB_USERNAME", "apexone")
    db_pass = creds.get("DB_PASSWORD", "")
    if not db_pass:
        ssh.close()
        raise SystemExit("Could not read DB_PASSWORD from NEW .env")

    log(f"DB={db} USER={db_user}")

    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
rm -f /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf
tar -C /var/www -czf /tmp/apexone-app.tgz \\
  --exclude='apexone/node_modules' \\
  --exclude='apexone/.git' \\
  --exclude='apexone/storage/logs/*.log' \\
  --exclude='apexone/storage/framework/cache/data/*' \\
  --exclude='apexone/storage/framework/sessions/*' \\
  --exclude='apexone/storage/framework/views/*' \\
  apexone
DB_USER=$(grep ^DB_USERNAME= {APP_REMOTE}/.env | cut -d= -f2- | tr -d '"')
DB_PASS=$(grep ^DB_PASSWORD= {APP_REMOTE}/.env | cut -d= -f2- | tr -d '"')
DB_NAME=$(grep ^DB_DATABASE= {APP_REMOTE}/.env | cut -d= -f2- | tr -d '"')
mysqldump --single-transaction --quick --routines --triggers -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip -c > /tmp/apexone-db.sql.gz
tar -C /etc/systemd/system -czf /tmp/apexone-units.tgz \\
  apexone-queue.service apex-call-events-ws.service 2>/dev/null || \\
  tar -C /etc/systemd/system -czf /tmp/apexone-units.tgz apexone-queue.service 2>/dev/null || true
if [ -f /etc/nginx/sites-available/apexone ]; then
  cp /etc/nginx/sites-available/apexone /tmp/apexone-nginx.conf
else
  echo '# placeholder' > /tmp/apexone-nginx.conf
fi
chmod 644 /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-nginx.conf
chown {NEW["user"]}:{NEW["user"]} /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-nginx.conf
[ -f /tmp/apexone-units.tgz ] && chown {NEW["user"]}:{NEW["user"]} /tmp/apexone-units.tgz || true
ls -lh /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-nginx.conf
echo PACKAGE_OK
""",
        timeout=1200,
    )
    log(out[-3000:])
    if "PACKAGE_OK" not in out:
        ssh.close()
        raise SystemExit("Packaging on NEW server failed")

    STAGING.mkdir(parents=True, exist_ok=True)
    for name in ("apexone-app.tgz", "apexone-db.sql.gz", "apexone-nginx.conf"):
        local = STAGING / name
        log(f"  ← /tmp/{name}")
        sftp_get(ssh, f"/tmp/{name}", local)
        log(f"     {local.stat().st_size / 1024 / 1024:.1f} MB")

    # units optional
    try:
        sftp_get(ssh, "/tmp/apexone-units.tgz", STAGING / "apexone-units.tgz")
    except Exception:
        log("  (no systemd units tarball — ok)")

    (STAGING / "db_creds.env").write_text(
        f"DB_DATABASE={db}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\n",
        encoding="utf-8",
    )

    sudo(
        ssh,
        pw,
        "rm -f /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf",
    )
    ssh.close()
    return creds


def phase_restore_old(creds: dict) -> None:
    log("\n=== PHASE 2: Restore onto OLD (testing only) ===")
    log(f"Target: {OLD['host']}")
    log(f"APP_URL will be {TEST_APP_URL} — NOT {PROD_DOMAIN}")
    log(f"Production domain {PROD_DOMAIN} remains on NEW {NEW['host']}")

    ssh = connect(OLD)
    pw = OLD["password"]
    db = creds["DB_DATABASE"]
    db_user = creds["DB_USERNAME"]
    db_pass = creds["DB_PASSWORD"]

    run(ssh, "mkdir -p /tmp/apexone-sync && chmod 777 /tmp/apexone-sync")
    for name in ("apexone-app.tgz", "apexone-db.sql.gz", "apexone-nginx.conf"):
        log(f"  → {name}")
        sftp_put(ssh, STAGING / name, f"/tmp/apexone-sync/{name}")
    if (STAGING / "apexone-units.tgz").exists():
        sftp_put(ssh, STAGING / "apexone-units.tgz", "/tmp/apexone-sync/apexone-units.tgz")

    bootstrap = STAGING / "mysql_bootstrap.sql"
    safe_pass = db_pass.replace("\\", "\\\\").replace("'", "''")
    bootstrap.write_text(
        f"""CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '{db_user}'@'localhost' IDENTIFIED BY '{safe_pass}';
ALTER USER '{db_user}'@'localhost' IDENTIFIED BY '{safe_pass}';
GRANT ALL PRIVILEGES ON `{db}`.* TO '{db_user}'@'localhost';
FLUSH PRIVILEGES;
""",
        encoding="utf-8",
    )
    sftp_put(ssh, bootstrap, "/tmp/apexone-sync/mysql_bootstrap.sql")

    # Testing nginx: listen on IP / default_server — do NOT claim production DNS.
    test_nginx = STAGING / "apexone-testing-nginx.conf"
    test_nginx.write_text(
        f"""# TESTING SERVER ONLY — production domain stays on {NEW["host"]}
server {{
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name {OLD["host"]} _;

    root {APP_REMOTE}/public;
    index index.php;

    client_max_body_size 100M;

    location / {{
        try_files $uri $uri/ /index.php?$query_string;
    }}

    location ~ \\.php$ {{
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }}

    location ~ /\\.(?!well-known).* {{
        deny all;
    }}
}}
""",
        encoding="utf-8",
    )
    sftp_put(ssh, test_nginx, "/tmp/apexone-sync/apexone-testing-nginx.conf")

    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
export DEBIAN_FRONTEND=noninteractive

# Ensure stack pieces exist (best-effort; old server already had LEMP)
command -v php8.3 >/dev/null || apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl
command -v nginx >/dev/null
command -v mysql >/dev/null

mysql < /tmp/apexone-sync/mysql_bootstrap.sql
gunzip -c /tmp/apexone-sync/apexone-db.sql.gz | mysql {db}
echo DB_RESTORE_OK

mkdir -p /var/www
TS=$(date +%Y%m%d%H%M%S)
if [ -d {APP_REMOTE} ]; then
  mv {APP_REMOTE} /var/www/apexone.bak.$TS
fi
mkdir -p /var/www/apexone.new
tar -C /var/www/apexone.new -xzf /tmp/apexone-sync/apexone-app.tgz
if [ -d /var/www/apexone.new/apexone ]; then
  mv /var/www/apexone.new/apexone {APP_REMOTE}
else
  mv /var/www/apexone.new {APP_REMOTE}
fi
rm -rf /var/www/apexone.new

# Force testing identity — never serve as production domain
cd {APP_REMOTE}
if [ -f .env ]; then
  sed -i 's|^APP_URL=.*|APP_URL={TEST_APP_URL}|' .env
  sed -i 's|^APP_ENV=.*|APP_ENV=local|' .env
  grep -q '^APP_ENV=' .env || echo 'APP_ENV=local' >> .env
  # Mark clearly
  grep -q '^APEX_SERVER_ROLE=' .env && sed -i 's|^APEX_SERVER_ROLE=.*|APEX_SERVER_ROLE=testing|' .env || echo 'APEX_SERVER_ROLE=testing' >> .env
  grep -q '^APEX_PRODUCTION_HOST=' .env && sed -i 's|^APEX_PRODUCTION_HOST=.*|APEX_PRODUCTION_HOST={NEW["host"]}|' .env || echo 'APEX_PRODUCTION_HOST={NEW["host"]}' >> .env
fi

# Testing nginx (IP) — do not bind production domain as sole server_name
cp /tmp/apexone-sync/apexone-testing-nginx.conf /etc/nginx/sites-available/apexone-testing
ln -sfn /etc/nginx/sites-available/apexone-testing /etc/nginx/sites-enabled/apexone-testing
# Keep old apexone site disabled for domain confusion; prefer testing site
rm -f /etc/nginx/sites-enabled/default
# If a prod-domain vhost exists, leave file but disable symlink that steals attention
if [ -L /etc/nginx/sites-enabled/apexone ]; then
  rm -f /etc/nginx/sites-enabled/apexone
fi

if [ -f /tmp/apexone-sync/apexone-units.tgz ]; then
  tar -C /etc/systemd/system -xzf /tmp/apexone-sync/apexone-units.tgz || true
fi

mkdir -p {APP_REMOTE}/storage/framework/cache {APP_REMOTE}/storage/framework/sessions {APP_REMOTE}/storage/framework/views {APP_REMOTE}/storage/logs {APP_REMOTE}/bootstrap/cache
chown -R www-data:www-data {APP_REMOTE}
chmod -R ug+rwx {APP_REMOTE}/storage {APP_REMOTE}/bootstrap/cache

if [ -f {APP_REMOTE}/services/call-events-ws/package.json ]; then
  cd {APP_REMOTE}/services/call-events-ws
  sudo -u www-data npm install --omit=dev || true
fi

cd {APP_REMOTE}
sudo -u www-data php artisan config:clear || true
sudo -u www-data php artisan route:clear || true
sudo -u www-data php artisan view:clear || true
sudo -u www-data php artisan cache:clear || true

# Write a visible testing banner file
cat > {APP_REMOTE}/TESTING_SERVER.txt <<'EOF'
THIS IS THE TESTING SERVER (old IP).
Production domain crm.apexonepayments.com stays on the NEW server.
Do not point DNS here.
EOF

nginx -t
systemctl daemon-reload || true
systemctl restart php8.3-fpm nginx || true
systemctl enable --now apexone-queue.service 2>/dev/null || true
systemctl enable --now apex-call-events-ws.service 2>/dev/null || true
systemctl restart apexone-queue.service 2>/dev/null || true

curl -sI http://127.0.0.1/up | head -15
curl -s -o /dev/null -w 'UP=%{{http_code}}\\n' http://127.0.0.1/up
curl -s -o /dev/null -w 'LOGIN=%{{http_code}}\\n' http://127.0.0.1/admin/login
grep -E '^(APP_URL|APP_ENV|APEX_SERVER_ROLE)=' {APP_REMOTE}/.env
echo SYNC_TEST_DONE
""",
        timeout=1800,
    )
    log(out[-8000:])
    ssh.close()
    if "SYNC_TEST_DONE" not in out:
        raise SystemExit("Restore onto OLD testing server failed")


def phase_smoke_old() -> None:
    log("\n=== PHASE 3: Smoke test OLD (by IP) ===")
    ssh = connect(OLD)
    pw = OLD["password"]
    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
echo '--- role ---'
grep -E '^(APP_URL|APP_ENV|APEX_SERVER_ROLE|APEX_PRODUCTION_HOST)=' {APP_REMOTE}/.env || true
echo '--- health ---'
curl -s -o /dev/null -w 'up=%{{http_code}}\\n' http://127.0.0.1/up
curl -s -o /dev/null -w 'admin_login=%{{http_code}}\\n' http://127.0.0.1/admin/login
curl -s -o /dev/null -w 'portal_login=%{{http_code}}\\n' http://127.0.0.1/portal/login
echo '--- db ---'
cd {APP_REMOTE} && sudo -u www-data php artisan db:show 2>/dev/null | head -15 || true
mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='apexone';" || true
echo SMOKE_OK
""",
        timeout=120,
    )
    log(out)
    ssh.close()
    if "SMOKE_OK" not in out:
        raise SystemExit("OLD smoke tests failed")


def phase_confirm_new_untouched() -> None:
    log("\n=== PHASE 4: Confirm NEW still owns production domain ===")
    ssh = connect(NEW)
    pw = NEW["password"]
    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
grep -E '^APP_URL=' {APP_REMOTE}/.env
curl -sk -o /dev/null -w 'prod_login=%{{http_code}}\\n' -H 'Host: {PROD_DOMAIN}' --resolve {PROD_DOMAIN}:443:127.0.0.1 https://{PROD_DOMAIN}/admin/login || \\
curl -sk -o /dev/null -w 'prod_login=%{{http_code}}\\n' -H 'Host: {PROD_DOMAIN}' https://127.0.0.1/admin/login
echo NEW_OK
""",
        timeout=60,
    )
    log(out)
    ssh.close()
    if "NEW_OK" not in out:
        raise SystemExit("NEW production check failed — investigate before more changes")


def main() -> int:
    log("=" * 64)
    log("SYNC NEW (prod) → OLD (testing)")
    log("=" * 64)
    log(f"NEW (production): {NEW['host']}  domain={PROD_DOMAIN}  [UNCHANGED]")
    log(f"OLD (testing):    {OLD['host']}  APP_URL={TEST_APP_URL}")
    log("DNS will NOT be changed.")
    log("=" * 64)

    STAGING.mkdir(parents=True, exist_ok=True)
    t0 = time.time()
    creds = phase_package_new()
    phase_restore_old(creds)
    phase_smoke_old()
    phase_confirm_new_untouched()

    log("\n" + "=" * 64)
    log("DONE — remember:")
    log(f"  • Testing:    {TEST_APP_URL}/admin/login")
    log(f"  • Production: https://{PROD_DOMAIN}/  on {NEW['host']}")
    log("  • Old server = testing only. Domain stays on new server.")
    log(f"  Elapsed: {int(time.time() - t0)}s")
    log("=" * 64)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"FATAL: {exc}")
        raise
