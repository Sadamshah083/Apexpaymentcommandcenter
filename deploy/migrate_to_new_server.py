#!/usr/bin/env python3
"""
Migrate ApexOne CRM from old prod → new server.
Keeps domain crm.apexonepayments.com; old server stays up until DNS cutover.
"""

from __future__ import annotations

import os
import shlex
import sys
import time
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
STAGING = ROOT / "deploy" / "_migrate_staging"

OLD = {
    "host": "203.215.160.44",
    "user": "issac",
    "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
}
NEW = {
    "host": "203.215.161.236",
    "user": "ateg",
    "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
}

APP_REMOTE = "/var/www/apexone"
DOMAIN = "crm.apexonepayments.com"


def connect(cfg: dict) -> paramiko.SSHClient:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=45)
    return ssh


def sudo(ssh: paramiko.SSHClient, password: str, cmd: str, timeout: int = 600) -> tuple[int, str]:
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


def phase_install_new() -> None:
    log("\n=== PHASE 1: Install LEMP + tools on NEW server ===")
    ssh = connect(NEW)
    pw = NEW["password"]
    code, out = sudo(
        ssh,
        pw,
        r"""
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx mysql-server redis-server supervisor curl unzip git \
  php8.3 php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring php8.3-curl \
  php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl php8.3-sqlite3 php8.3-opcache \
  certbot python3-certbot-nginx
# Node 20 (for call-events-ws)
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt-get install -y nodejs
fi
# Composer
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
systemctl enable --now nginx mysql php8.3-fpm redis-server supervisor
php -v | head -1
nginx -v
mysql --version | head -1
node -v
composer -V | head -1
ufw allow OpenSSH || true
ufw allow 80/tcp || true
ufw allow 443/tcp || true
echo STACK_OK
""",
        timeout=1200,
    )
    log(out[-4000:])
    if code != 0 or "STACK_OK" not in out:
        ssh.close()
        raise SystemExit(f"Stack install failed code={code}")
    ssh.close()
    log("Stack installed.")


def phase_package_old() -> dict:
    log("\n=== PHASE 2: Package app + DB + SSL on OLD server ===")
    ssh = connect(OLD)
    pw = OLD["password"]

    # Read DB credentials (kept on server only)
    code, env_out = sudo(
        ssh,
        pw,
        "grep -E '^(DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' /var/www/apexone/.env",
    )
    creds = {}
    for line in env_out.splitlines():
        if "=" in line and not line.startswith("["):
            k, _, v = line.partition("=")
            creds[k.strip()] = v.strip().strip('"').strip("'")
    db = creds.get("DB_DATABASE", "apexone")
    db_user = creds.get("DB_USERNAME", "apexone")
    db_pass = creds.get("DB_PASSWORD", "")
    if not db_pass:
        ssh.close()
        raise SystemExit("Could not read DB_PASSWORD from old .env")

    log(f"DB={db} USER={db_user}")

    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
rm -f /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-ssl.tgz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf
# App archive (skip bulky rebuildable caches / local node tree)
tar -C /var/www -czf /tmp/apexone-app.tgz \
  --exclude='apexone/node_modules' \
  --exclude='apexone/.git' \
  --exclude='apexone/storage/logs/*.log' \
  --exclude='apexone/storage/framework/cache/data/*' \
  --exclude='apexone/storage/framework/sessions/*' \
  --exclude='apexone/storage/framework/views/*' \
  apexone
# DB dump using .env credentials
DB_USER=$(grep ^DB_USERNAME= /var/www/apexone/.env | cut -d= -f2- | tr -d '"')
DB_PASS=$(grep ^DB_PASSWORD= /var/www/apexone/.env | cut -d= -f2- | tr -d '"')
DB_NAME=$(grep ^DB_DATABASE= /var/www/apexone/.env | cut -d= -f2- | tr -d '"')
mysqldump --single-transaction --quick --routines --triggers -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" | gzip -c > /tmp/apexone-db.sql.gz
# SSL
if [ -d /etc/letsencrypt/live/{DOMAIN} ]; then
  tar -C /etc -czf /tmp/apexone-ssl.tgz \
    letsencrypt/live/{DOMAIN} \
    letsencrypt/archive/{DOMAIN} \
    letsencrypt/renewal/{DOMAIN}.conf
else
  tar -C /etc -czf /tmp/apexone-ssl.tgz letsencrypt
fi
# systemd units
tar -C /etc/systemd/system -czf /tmp/apexone-units.tgz \
  apexone-queue.service apex-call-events-ws.service apexone-comm-hub-monitor.service apexone-comm-hub-monitor.timer
# nginx site
cp /etc/nginx/sites-available/apexone /tmp/apexone-nginx.conf
chmod 644 /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-ssl.tgz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf
# allow download by ssh user
chown {OLD["user"]}:{OLD["user"]} /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-ssl.tgz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf
ls -lh /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-ssl.tgz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf
echo PACKAGE_OK
""",
        timeout=900,
    )
    log(out[-3000:])
    if "PACKAGE_OK" not in out:
        ssh.close()
        raise SystemExit("Packaging on old server failed")

    STAGING.mkdir(parents=True, exist_ok=True)
    files = [
        "apexone-app.tgz",
        "apexone-db.sql.gz",
        "apexone-ssl.tgz",
        "apexone-units.tgz",
        "apexone-nginx.conf",
    ]
    log("Downloading packages to local staging...")
    for name in files:
        local = STAGING / name
        log(f"  ← /tmp/{name}")
        sftp_get(ssh, f"/tmp/{name}", local)
        log(f"     {local.stat().st_size / 1024 / 1024:.1f} MB")

    # Persist DB creds for new server recreate (local only, not committed)
    (STAGING / "db_creds.env").write_text(
        f"DB_DATABASE={db}\nDB_USERNAME={db_user}\nDB_PASSWORD={db_pass}\n",
        encoding="utf-8",
    )

    # cleanup remote temp
    sudo(
        ssh,
        pw,
        "rm -f /tmp/apexone-app.tgz /tmp/apexone-db.sql.gz /tmp/apexone-ssl.tgz /tmp/apexone-units.tgz /tmp/apexone-nginx.conf",
    )
    ssh.close()
    return creds


def phase_upload_configure(creds: dict) -> None:
    log("\n=== PHASE 3: Upload + restore on NEW server ===")
    ssh = connect(NEW)
    pw = NEW["password"]
    db = creds["DB_DATABASE"]
    db_user = creds["DB_USERNAME"]
    db_pass = creds["DB_PASSWORD"]

    run(ssh, "mkdir -p /tmp/apexone-migrate && chmod 777 /tmp/apexone-migrate")
    log("Uploading packages...")
    for name in (
        "apexone-app.tgz",
        "apexone-db.sql.gz",
        "apexone-ssl.tgz",
        "apexone-units.tgz",
        "apexone-nginx.conf",
    ):
        log(f"  → {name}")
        sftp_put(ssh, STAGING / name, f"/tmp/apexone-migrate/{name}")

    # Write mysql bootstrap SQL via sftp to avoid shell-quoting password issues
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
    sftp_put(ssh, bootstrap, "/tmp/apexone-migrate/mysql_bootstrap.sql")

    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
export DEBIAN_FRONTEND=noninteractive

mysql < /tmp/apexone-migrate/mysql_bootstrap.sql
gunzip -c /tmp/apexone-migrate/apexone-db.sql.gz | mysql {db}
echo DB_RESTORE_OK

mkdir -p /var/www
rm -rf /var/www/apexone.new /var/www/apexone.bak
mkdir -p /var/www/apexone.new
tar -C /var/www/apexone.new -xzf /tmp/apexone-migrate/apexone-app.tgz
if [ -d /var/www/apexone.new/apexone ]; then
  if [ -d /var/www/apexone ]; then mv /var/www/apexone /var/www/apexone.bak; fi
  mv /var/www/apexone.new/apexone /var/www/apexone
  rm -rf /var/www/apexone.new
else
  if [ -d /var/www/apexone ]; then mv /var/www/apexone /var/www/apexone.bak; fi
  mv /var/www/apexone.new /var/www/apexone
fi

mkdir -p /etc/letsencrypt
tar -C /etc -xzf /tmp/apexone-migrate/apexone-ssl.tgz
if [ -d /etc/letsencrypt/archive/{DOMAIN} ]; then
  mkdir -p /etc/letsencrypt/live/{DOMAIN}
  cd /etc/letsencrypt/archive/{DOMAIN}
  LATEST=$(ls -1 fullchain*.pem 2>/dev/null | sed 's/fullchain//;s/\\.pem//' | sort -n | tail -1)
  if [ -n "$LATEST" ]; then
    ln -sfn ../../archive/{DOMAIN}/fullchain${{LATEST}}.pem /etc/letsencrypt/live/{DOMAIN}/fullchain.pem
    ln -sfn ../../archive/{DOMAIN}/privkey${{LATEST}}.pem /etc/letsencrypt/live/{DOMAIN}/privkey.pem
    ln -sfn ../../archive/{DOMAIN}/cert${{LATEST}}.pem /etc/letsencrypt/live/{DOMAIN}/cert.pem
    ln -sfn ../../archive/{DOMAIN}/chain${{LATEST}}.pem /etc/letsencrypt/live/{DOMAIN}/chain.pem
  fi
fi

tar -C /etc/systemd/system -xzf /tmp/apexone-migrate/apexone-units.tgz
cp /tmp/apexone-migrate/apexone-nginx.conf /etc/nginx/sites-available/apexone
ln -sfn /etc/nginx/sites-available/apexone /etc/nginx/sites-enabled/apexone
rm -f /etc/nginx/sites-enabled/default

mkdir -p /var/www/apexone/storage/framework/cache /var/www/apexone/storage/framework/sessions /var/www/apexone/storage/framework/views /var/www/apexone/storage/logs /var/www/apexone/bootstrap/cache
chown -R www-data:www-data /var/www/apexone
chmod -R ug+rwx /var/www/apexone/storage /var/www/apexone/bootstrap/cache

if [ -f /var/www/apexone/services/call-events-ws/package.json ]; then
  cd /var/www/apexone/services/call-events-ws
  sudo -u www-data npm install --omit=dev
fi

cd /var/www/apexone
sudo -u www-data php artisan config:clear || true
sudo -u www-data php artisan route:clear || true
sudo -u www-data php artisan view:clear || true
sudo -u www-data php artisan config:cache || true
sudo -u www-data php artisan route:cache || true

nginx -t
systemctl daemon-reload
systemctl restart php8.3-fpm nginx mysql
systemctl enable --now apexone-queue.service apex-call-events-ws.service
systemctl enable --now apexone-comm-hub-monitor.timer || true
systemctl restart apexone-queue.service apex-call-events-ws.service

systemctl is-active nginx php8.3-fpm mysql apexone-queue apex-call-events-ws
curl -skI -H 'Host: {DOMAIN}' https://127.0.0.1/ | head -20
curl -sk -o /dev/null -w 'HTTPS_STATUS=%{{http_code}}\\n' -H 'Host: {DOMAIN}' https://127.0.0.1/login
curl -sI -H 'Host: {DOMAIN}' http://127.0.0.1/ | head -10
echo MIGRATE_DONE
""",
        timeout=1200,
    )
    log(out[-6000:])
    ssh.close()
    if "MIGRATE_DONE" not in out:
        raise SystemExit("Configure/restore on new server failed")


def phase_smoke() -> None:
    log("\n=== PHASE 4: External smoke tests ===")
    ssh = connect(NEW)
    pw = NEW["password"]
    code, out = sudo(
        ssh,
        pw,
        f"""
set -e
echo '--- services ---'
systemctl is-active nginx php8.3-fpm mysql apexone-queue apex-call-events-ws
echo '--- local https ---'
curl -skI -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/ | head -15
echo '--- login page ---'
curl -sk -o /tmp/login.html -w 'code=%{{http_code}} size=%{{size_download}}\\n' -H 'Host: {DOMAIN}' --resolve {DOMAIN}:443:127.0.0.1 https://{DOMAIN}/login
grep -o 'Agent sign in\\|ApexOne Command Center\\|Sign in' /tmp/login.html | head -5
echo '--- db ping ---'
cd /var/www/apexone && sudo -u www-data php artisan db:show 2>/dev/null | head -20
mysql -N -e "SELECT COUNT(*) AS tables_count FROM information_schema.tables WHERE table_schema='apexone';"
echo SMOKE_OK
""",
        timeout=120,
    )
    log(out)
    ssh.close()
    if "SMOKE_OK" not in out:
        raise SystemExit("Smoke tests failed")


def main() -> int:
    log(f"Migrating ApexOne → {NEW['host']} (domain {DOMAIN} unchanged)")
    log("Old server stays LIVE until you switch DNS.")
    STAGING.mkdir(parents=True, exist_ok=True)

    phase_install_new()
    creds = phase_package_old()
    phase_upload_configure(creds)
    phase_smoke()

    log("\n" + "=" * 60)
    log("MIGRATION READY FOR DNS CUTOVER")
    log("=" * 60)
    log(f"New server IP:  {NEW['host']}")
    log(f"Domain:         https://{DOMAIN}/")
    log("Point your DNS A record for crm.apexonepayments.com → " + NEW["host"])
    log("Keep TTL low if possible; leave old server running until DNS propagates.")
    log("After DNS: certbot renew should keep working (certs already copied).")
    log("=" * 60)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        log(f"FATAL: {exc}")
        raise
