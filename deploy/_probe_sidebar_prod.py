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

from deploy._ssh import connect, sudo_run_batch


def main() -> int:
    ssh = connect()
    print(sudo_run_batch(ssh, [
        f"test -f {m.REMOTE_APP}/app/Http/Controllers/MapsScraperController.php && echo MAPS_CTRL=yes || echo MAPS_CTRL=no",
        f"grep -n maps-scraper {m.REMOTE_APP}/routes/web.php | head -10 || true",
        f"grep -n maps_scraper {m.REMOTE_APP}/config/admin_modules.php | head -10 || true",
        f"grep -n 'Maps Lead Scraper\\|label=\"Dashboard\"\\|All call logs' {m.REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-admin.blade.php | head -20",
        f"grep -c assign-leads-team-pick {m.REMOTE_APP}/resources/css/app.css",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
