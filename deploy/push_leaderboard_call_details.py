#!/usr/bin/env python3
"""Deploy weekly leaderboard call counts + agent call detail (duration/disposition)."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/SalesOps/SdrPerformanceService.php",
    "app/Services/Dashboard/DashboardDetailService.php",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/views/admin/dashboard/partials/detail-panel.blade.php",
    "resources/views/sales-ops/index.blade.php",
    "resources/views/sales-ops/performance.blade.php",
    "resources/views/pipeline/partials/dashboard-widgets.blade.php",
    "resources/js/sales-ops-sync.js",
    "resources/js/portal-dashboard.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-leaderboard-calls.log 2>&1
tail -n 18 /tmp/vite-leaderboard-calls.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan config:clear
php -l {REMOTE_APP}/app/Services/SalesOps/SdrPerformanceService.php
php -l {REMOTE_APP}/app/Services/Dashboard/DashboardDetailService.php
grep -n "calls_taken\\|agentCallHistory\\|talk_label\\|Calls taken" \\
  {REMOTE_APP}/app/Services/SalesOps/SdrPerformanceService.php \\
  {REMOTE_APP}/app/Services/Dashboard/DashboardDetailService.php \\
  {REMOTE_APP}/resources/views/admin/dashboard/index.blade.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Leaderboard call details deployed. Ctrl+F5 admin dashboard.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
