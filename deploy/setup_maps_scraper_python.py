#!/usr/bin/env python3
"""Install Python deps + Playwright Chromium for Maps scraper on production."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch

VENV = f"{REMOTE_APP}/tools/google-maps-scraper/.venv"


def main() -> int:
    ssh = connect()
    try:
        out = sudo_run_batch(ssh, [
            f"apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-venv python3-pip > /tmp/maps-apt.log 2>&1; echo APT:$?",
            f"cd {REMOTE_APP}/tools/google-maps-scraper && python3 -m venv .venv && .venv/bin/pip install -U pip wheel > /tmp/maps-pip1.log 2>&1; echo VENV:$?",
            f"cd {REMOTE_APP}/tools/google-maps-scraper && .venv/bin/pip install -r requirements.txt > /tmp/maps-pip2.log 2>&1; echo REQS:$?; tail -n 15 /tmp/maps-pip2.log",
            f"cd {REMOTE_APP}/tools/google-maps-scraper && .venv/bin/playwright install --with-deps chromium > /tmp/maps-pw.log 2>&1; echo PW:$?; tail -n 20 /tmp/maps-pw.log",
            f"{VENV}/bin/python -c 'import playwright,pandas; print(\"IMPORT_OK\", playwright.__version__)'",
            # Point Laravel at the venv python
            f"grep -q '^MAPS_SCRAPER_PYTHON=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_PYTHON=.*|MAPS_SCRAPER_PYTHON={VENV}/bin/python|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_PYTHON={VENV}/bin/python' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_HEADLESS=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_HEADLESS=.*|MAPS_SCRAPER_HEADLESS=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_HEADLESS=true' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_ENABLED=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_ENABLED=.*|MAPS_SCRAPER_ENABLED=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_ENABLED=true' >> {REMOTE_APP}/.env",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"grep MAPS_SCRAPER {REMOTE_APP}/.env",
            f"chown -R www-data:www-data {REMOTE_APP}/tools/google-maps-scraper {REMOTE_APP}/storage/app/maps-scraper",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("PLAYWRIGHT_SETUP_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
