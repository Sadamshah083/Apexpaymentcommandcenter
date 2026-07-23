#!/usr/bin/env python3
"""Deploy seekable recordings, member 403 fix, role popup, manager access, error monitoring."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Http/Middleware/EnsureCanManageMembers.php",
    "app/Http/Controllers/ErrorMonitoringController.php",
    "app/Models/ApplicationError.php",
    "app/Models/User.php",
    "app/Services/Monitoring/ApplicationErrorReporter.php",
    "bootstrap/app.php",
    "config/admin_modules.php",
    "database/migrations/2026_07_19_053000_create_application_errors_table.php",
    "resources/views/error-monitoring/index.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/js/member-management.js",
    "resources/css/app.css",
    "routes/web.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("1) Upload...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("2) Migrate + build + clear caches...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force",
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-continue.log 2>&1; echo BUILD:$?; tail -n 20 /tmp/vite-continue.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=error-monitoring --columns=method,uri,name 2>/dev/null | head -20",
    ], check=False))

    ssh.close()
    print("Done.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
