#!/usr/bin/env python3
"""Deploy functional Today's Team Activity + dashboard card UI."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Portal/PortalDashboardService.php",
    "app/Services/Dashboard/DashboardDetailService.php",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/views/admin/dashboard/partials/detail-panel.blade.php",
    "resources/css/app.css",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php -l app/Services/Portal/PortalDashboardService.php
php -l app/Services/Dashboard/DashboardDetailService.php
php artisan view:clear
php artisan config:clear
npm run build > /tmp/vite-team-activity.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-team-activity.log | tr -cd '\\11\\12\\15\\40-\\176'
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$w = App\\Models\\Workspace::query()->first();
$s = app(App\\Services\\Portal\\PortalDashboardService::class)->adminOperationalSummary($w);
echo json_encode($s["today_activity"], JSON_PRETTY_PRINT), "\\n";
echo "total_calls=", $s["total_calls_today"], " connected=", $s["connected_today"], "\\n";
'
chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed team activity UI. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
