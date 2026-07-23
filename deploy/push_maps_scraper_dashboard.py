#!/usr/bin/env python3
"""Deploy Maps Lead Scraper dashboard + Start fix (Playwright, free)."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
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
REMOTE = "/var/www/apexone"
FILES = [
    "config/maps_scraper.php",
    "app/Http/Controllers/MapsScraperController.php",
    "app/Services/MapsScraper/MapsScraperService.php",
    "resources/views/maps-scraper/index.blade.php",
    "resources/views/maps-scraper/show.blade.php",
]


def deploy_one(cfg: dict) -> None:
    print(f"\n=== {cfg['label']} {cfg['host']} ===", flush=True)
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(cfg["host"], username=cfg["user"], password=cfg["password"], timeout=35)
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

    inner = f"""
set -e
cd {REMOTE}
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
grep -n "priority_categories" config/maps_scraper.php | head -2
grep -n "launchWorker\\|afterResponse" app/Http/Controllers/MapsScraperController.php | head -5
grep -n "Start Google Maps" resources/views/maps-scraper/index.blade.php | head -2
grep -n "Process::path" -n app/Services/MapsScraper/MapsScraperService.php | head -3 || true
grep -n "->start(" app/Services/MapsScraper/MapsScraperService.php | head -2
php -l app/Http/Controllers/MapsScraperController.php
php -l app/Services/MapsScraper/MapsScraperService.php
echo DONE_{cfg['label']}
"""
    cmd = f"echo {shlex.quote(cfg['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=120)
    print((o.read() + e.read()).decode(errors="replace")[-3000:], flush=True)
    ssh.close()


def main() -> int:
    for cfg in TARGETS:
        deploy_one(cfg)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
