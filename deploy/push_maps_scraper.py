#!/usr/bin/env python3
"""Deploy Maps Lead Scraper integration to production."""

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

FILES = [
    "config/admin_modules.php",
    "config/maps_scraper.php",
    "routes/web.php",
    "app/Http/Controllers/MapsScraperController.php",
    "app/Jobs/RunMapsScrapeJob.php",
    "app/Models/MapsScrapeJob.php",
    "app/Services/MapsScraper/MapsScraperService.php",
    "app/Services/MapsScraper/MapsScraperExcelExporter.php",
    "database/migrations/2026_07_20_190000_create_maps_scrape_jobs_table.php",
    "resources/views/maps-scraper/index.blade.php",
    "resources/views/maps-scraper/show.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "tests/Unit/Services/MapsScraper/MapsScraperExcelExporterTest.php",
    "tools/google-maps-scraper/apex_bridge.py",
    "tools/google-maps-scraper/browser_launch.py",
    "tools/google-maps-scraper/main.py",
    "tools/google-maps-scraper/scrape_state.py",
    "tools/google-maps-scraper/scrape_all_states.py",
    "tools/google-maps-scraper/state_grid.py",
    "tools/google-maps-scraper/fetch_state_cities.py",
    "tools/google-maps-scraper/requirements.txt",
    "tools/google-maps-scraper/APEXONE.md",
    "tools/google-maps-scraper/.gitignore",
    "tools/google-maps-scraper/data/us_states.json",
    "tools/google-maps-scraper/data/state_bounds.json",
]


def main() -> int:
    pairs = []
    for rel in FILES:
        local = ROOT / rel
        if not local.is_file():
            print(f"MISSING {rel}")
            return 1
        pairs.append((local, rel))

    # Optional city cache
    cities = ROOT / "tools/google-maps-scraper/data/cities/alabama_cities.txt"
    if cities.is_file():
        pairs.append((cities, "tools/google-maps-scraper/data/cities/alabama_cities.txt"))

    ssh = connect()
    try:
        print("Uploading Maps Lead Scraper…")
        upload_files(ssh, pairs, app_root=REMOTE_APP)
        out = sudo_run_batch(ssh, [
            f"mkdir -p {REMOTE_APP}/tools/google-maps-scraper/data/cities {REMOTE_APP}/tools/google-maps-scraper/data/progress {REMOTE_APP}/tools/google-maps-scraper/output {REMOTE_APP}/storage/app/maps-scraper",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan test --filter=MapsScraperExcelExporterTest 2>&1 | tail -n 40",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=maps-scraper 2>&1 | tail -n 20",
            f"test -f {REMOTE_APP}/tools/google-maps-scraper/apex_bridge.py && echo BRIDGE_OK",
            f"grep -n maps_scraper {REMOTE_APP}/config/admin_modules.php | head -3",
            f"grep -n 'Maps Lead Scraper' {REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-admin.blade.php | head -3",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
