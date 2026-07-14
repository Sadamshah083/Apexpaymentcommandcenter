#!/usr/bin/env python3
"""Deploy Call Monitoring sidebar + wallboard."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/CommunicationsAccessService.php",
    "app/Http/Controllers/CallMonitoringController.php",
    "routes/web.php",
    "resources/views/communications/monitoring/index.blade.php",
    "resources/views/communications/monitoring/portal.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/views/components/sidebar/link.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/js/call-monitoring.js",
    "resources/js/app.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets + clearing caches...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        ],
    )
    print(sudo_run(ssh, "curl -fsS http://127.0.0.1/admin/login -o /dev/null -w '%{http_code}' || true"))
    ssh.close()
    print("Call Monitoring deployed. Hard-refresh (Ctrl+F5).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
