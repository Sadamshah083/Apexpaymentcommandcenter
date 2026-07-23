#!/usr/bin/env python3
"""Install Maps scraper Python venv + Playwright on OLD and NEW; deploy unlimited-limit fix."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
REMOTE = "/var/www/apexone"
VENV = f"{REMOTE}/tools/google-maps-scraper/.venv"
TARGETS = [
    {
        "label": "OLD",
        "host": "203.215.160.44",
        "user": "issac",
        "password": os.environ.get("OLD_DEPLOY_PASSWORD", "SadamShah123"),
    },
    {
        "label": "NEW",
        "host": "203.215.161.236",
        "user": "ateg",
        "password": os.environ.get("NEW_DEPLOY_PASSWORD", "balitech1"),
    },
]
FILES = [
    "app/Services/MapsScraper/MapsScraperService.php",
    "app/Http/Controllers/MapsScraperController.php",
    "config/maps_scraper.php",
    "resources/views/maps-scraper/index.blade.php",
    "tools/google-maps-scraper/apex_bridge.py",
    "tools/google-maps-scraper/requirements.txt",
]


def deploy_one(cfg: dict) -> None:
    print(f"\n=== {cfg['label']} {cfg['host']} ===", flush=True)
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=40)
    except Exception as exc:
        print(f"CONNECT_FAIL: {exc}", flush=True)
        return

    sys.path.insert(0, str(ROOT))
    import deploy._ssh as ssh_mod

    ssh_mod.HOST = cfg["host"]
    ssh_mod.USER = cfg["user"]
    ssh_mod.PASSWORD = cfg["password"]
    ssh_mod.REMOTE_APP = REMOTE
    from deploy._ssh import upload_files

    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE)

    # Force controller via SFTP too (avoid $ expansion issues in some tar paths)
    sftp = ssh.open_sftp()
    for rel in FILES:
        sftp.put(str(ROOT / rel), f"/tmp/{Path(rel).name}")
    sftp.close()

    copy_cmds = "\n".join(
        f"cp /tmp/{Path(rel).name} {REMOTE}/{rel}" for rel in FILES
    )

    inner = f"""
set -e
{copy_cmds}
mkdir -p {REMOTE}/tools/google-maps-scraper/data/cities {REMOTE}/tools/google-maps-scraper/output {REMOTE}/storage/app/maps-scraper
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-venv python3-pip > /tmp/maps-apt.log 2>&1 || true
cd {REMOTE}/tools/google-maps-scraper
rm -rf .venv
python3 -m venv .venv
.venv/bin/pip install -U pip wheel > /tmp/maps-pip1.log 2>&1
.venv/bin/pip install -r requirements.txt > /tmp/maps-pip2.log 2>&1
tail -n 8 /tmp/maps-pip2.log
.venv/bin/playwright install --with-deps chromium > /tmp/maps-pw.log 2>&1
tail -n 12 /tmp/maps-pw.log
test -x {VENV}/bin/python
{VENV}/bin/python -c 'import playwright,pandas; print("IMPORT_OK", playwright.__version__)'
# Point Laravel at working venv python
if grep -q '^MAPS_SCRAPER_PYTHON=' {REMOTE}/.env; then
  sed -i 's|^MAPS_SCRAPER_PYTHON=.*|MAPS_SCRAPER_PYTHON={VENV}/bin/python|' {REMOTE}/.env
else
  echo 'MAPS_SCRAPER_PYTHON={VENV}/bin/python' >> {REMOTE}/.env
fi
grep -q '^MAPS_SCRAPER_ENABLED=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_ENABLED=.*|MAPS_SCRAPER_ENABLED=true|' {REMOTE}/.env || echo 'MAPS_SCRAPER_ENABLED=true' >> {REMOTE}/.env
grep -q '^MAPS_SCRAPER_HEADLESS=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_HEADLESS=.*|MAPS_SCRAPER_HEADLESS=true|' {REMOTE}/.env || echo 'MAPS_SCRAPER_HEADLESS=true' >> {REMOTE}/.env
grep -q '^MAPS_SCRAPER_TIMEOUT=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_TIMEOUT=.*|MAPS_SCRAPER_TIMEOUT=28800|' {REMOTE}/.env || echo 'MAPS_SCRAPER_TIMEOUT=28800' >> {REMOTE}/.env
chown -R www-data:www-data {REMOTE}/tools/google-maps-scraper {REMOTE}/storage/app/maps-scraper
cd {REMOTE}
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
php -l app/Services/MapsScraper/MapsScraperService.php
php -l app/Http/Controllers/MapsScraperController.php
sudo -u www-data php -r "require 'vendor/autoload.php'; \\$app=require 'bootstrap/app.php'; \\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); echo app(App\\\\Services\\\\MapsScraper\\\\MapsScraperService::class)->resolvePython(), PHP_EOL;"
grep '^MAPS_SCRAPER_' {REMOTE}/.env
echo DONE_{cfg['label']}
"""
    cmd = f"echo {shlex.quote(cfg['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=1800)
    print((o.read() + e.read()).decode(errors="replace")[-6000:], flush=True)
    ssh.close()


def main() -> int:
    for cfg in TARGETS:
        deploy_one(cfg)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
