#!/usr/bin/env python3
"""Deploy Dashboard rename + Total Calls Today KPIs + green UI + disposition timing."""
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
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/admin/dashboard/index.blade.php",
    "app/Services/Portal/PortalDashboardService.php",
    "app/Http/Controllers/AdminDashboardController.php",
    "config/admin_modules.php",
    "config/app.php",
    "resources/css/app.css",
    "resources/js/communications-auto-dial.js",
    "resources/js/app.js",
    "resources/views/dashboard.blade.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
grep -n 'Total calls for today\\|total_calls_today\\|admin-dash-hero-kpis\\|suppressMs' \\
  resources/views/admin/dashboard/index.blade.php \\
  app/Services/Portal/PortalDashboardService.php \\
  resources/js/communications-auto-dial.js \\
  resources/views/layouts/partials/sidebar-nav-admin.blade.php 2>/dev/null | head -40
php -l app/Services/Portal/PortalDashboardService.php
php -l app/Http/Controllers/AdminDashboardController.php
php artisan view:clear
php artisan config:clear
php artisan route:clear
npm run build > /tmp/vite-dashboard-kpis.log 2>&1
echo BUILD:$?
tail -n 16 /tmp/vite-dashboard-kpis.log | tr -cd '\\11\\12\\15\\40-\\176'
ls -lt public/build/assets/app-*.css public/build/assets/communications-auto-dial-*.js 2>/dev/null | head -6
chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed Dashboard KPIs + green UI + disposition timing. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
