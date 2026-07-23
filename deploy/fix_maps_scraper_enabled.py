#!/usr/bin/env python3
"""Diagnose and fix Maps scraper disabled on production."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files


def main() -> int:
    ssh = connect()
    try:
        # Ensure latest config + service are on server
        upload_files(
            ssh,
            [
                (ROOT / "config/maps_scraper.php", "config/maps_scraper.php"),
                (ROOT / "app/Services/MapsScraper/MapsScraperService.php", "app/Services/MapsScraper/MapsScraperService.php"),
            ],
            app_root=REMOTE_APP,
        )

        out = sudo_run_batch(ssh, [
            f"grep -n MAPS_SCRAPER {REMOTE_APP}/.env || echo NO_MAPS_ENV",
            f"grep -n enabled {REMOTE_APP}/config/maps_scraper.php | head -5",
            # Force enable in .env
            f"grep -q '^MAPS_SCRAPER_ENABLED=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_ENABLED=.*|MAPS_SCRAPER_ENABLED=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_ENABLED=true' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_PYTHON=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_PYTHON=.*|MAPS_SCRAPER_PYTHON={REMOTE_APP}/tools/google-maps-scraper/.venv/bin/python|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_PYTHON={REMOTE_APP}/tools/google-maps-scraper/.venv/bin/python' >> {REMOTE_APP}/.env",
            f"grep -q '^MAPS_SCRAPER_HEADLESS=' {REMOTE_APP}/.env && sed -i 's|^MAPS_SCRAPER_HEADLESS=.*|MAPS_SCRAPER_HEADLESS=true|' {REMOTE_APP}/.env || echo 'MAPS_SCRAPER_HEADLESS=true' >> {REMOTE_APP}/.env",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan cache:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            # Verify runtime config
            f"cd {REMOTE_APP} && sudo -u www-data php -r \"require 'vendor/autoload.php'; \\$app=require 'bootstrap/app.php'; \\$app->make(Illuminate\\\\Contracts\\\\Console\\\\Kernel::class)->bootstrap(); var_export(config('maps_scraper.enabled')); echo PHP_EOL; echo config('maps_scraper.python').PHP_EOL; echo config('maps_scraper.path').PHP_EOL;\"",
            f"test -x {REMOTE_APP}/tools/google-maps-scraper/.venv/bin/python && echo PYTHON_OK",
            f"test -f {REMOTE_APP}/tools/google-maps-scraper/apex_bridge.py && echo BRIDGE_OK",
            # Reset failed job #1 to pending so user can retry easily via UI or we re-dispatch
            f"cd {REMOTE_APP} && sudo -u www-data php artisan tinker --execute=\"\\\\App\\\\Models\\\\MapsScrapeJob::query()->where('id',1)->update(['status'=>'pending','progress_pct'=>0,'progress_message'=>'Queued for retry','error_message'=>null]); echo 'JOB1_RESET';\"",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("FIXED")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
