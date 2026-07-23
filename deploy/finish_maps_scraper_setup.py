#!/usr/bin/env python3
"""Finish Maps scraper env + www-data Playwright browsers."""

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
            f"test -x {VENV}/bin/python && echo VENV_OK",
            f"{VENV}/bin/python -c 'import pandas, playwright.sync_api; print(\"IMPORT_OK\")'",
            # Ensure browsers available for www-data
            f"mkdir -p /var/www/.cache && chown -R www-data:www-data /var/www/.cache",
            f"sudo -u www-data env HOME=/var/www {VENV}/bin/playwright install chromium > /tmp/maps-pw-www.log 2>&1; echo WWW_PW:$?; tail -n 15 /tmp/maps-pw-www.log",
            f"grep -q '^MAPS_SCRAPER_PYTHON=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_PYTHON=.*|MAPS_SCRAPER_PYTHON={VENV}/bin/python|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_PYTHON={VENV}/bin/python' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_HEADLESS=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_HEADLESS=.*|MAPS_SCRAPER_HEADLESS=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_HEADLESS=true' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_ENABLED=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_ENABLED=.*|MAPS_SCRAPER_ENABLED=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_ENABLED=true' >> {REMOTE_APP}/.env",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"grep MAPS_SCRAPER {REMOTE_APP}/.env",
            f"chown -R www-data:www-data {REMOTE_APP}/tools/google-maps-scraper {REMOTE_APP}/storage/app/maps-scraper",
            # Quick CSV->Excel smoke via artisan tinker-free PHP one-liner
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php'; \\$app=require 'bootstrap/app.php'; \\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); \\$e=app(App\\\\Services\\\\MapsScraper\\\\MapsScraperExcelExporter::class); \\$dir=storage_path('app/maps-scraper/_smoke'); @mkdir(\\$dir,0755,true); \\$r=\\$e->exportGroupedByAreaCode([['name'=>'A','phone_number'=>'3345551212','place_type'=>'Locksmith','reviews_count'=>1],['name'=>'B','phone_number'=>'2515551212','place_type'=>'Locksmith','reviews_count'=>1]], \\$dir, 'smoke'); echo 'SMOKE '.\\$r['file_count'].' files '.basename(\\$r['zip_path']).PHP_EOL;\"",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("FINISH_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
