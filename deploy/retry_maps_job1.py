#!/usr/bin/env python3
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
        upload_files(
            ssh,
            [
                (ROOT / "config/maps_scraper.php", "config/maps_scraper.php"),
                (ROOT / "app/Services/MapsScraper/MapsScraperService.php", "app/Services/MapsScraper/MapsScraperService.php"),
                (ROOT / "deploy/_retry_maps_job1.php", "storage/app/_retry_maps_job1.php"),
            ],
            app_root=REMOTE_APP,
        )
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            f"chown www-data:www-data {REMOTE_APP}/storage/app/_retry_maps_job1.php",
            f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_retry_maps_job1.php",
            f"rm -f {REMOTE_APP}/storage/app/_retry_maps_job1.php",
            "ps aux | grep -E 'queue:work|horizon' | grep -v grep | head -5 || echo NO_QUEUE_WORKER",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("DONE")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
