#!/usr/bin/env python3
"""Deploy Call Monitoring reorder + not-logged-in + light green header."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Services/Communications/CallMonitoringService.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/views/communications/monitoring/partials/row.blade.php",
    "resources/js/call-monitoring.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"php -l {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php",
            check=False,
        )
    )
    print("Building assets + clearing caches...")
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && npm run build",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
        ],
    )
    print(
        sudo_run(
            ssh,
            f"""
grep -n "not_logged_in\\|buildNotLoggedInRows\\|bbf7d0\\|Logged in" \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php \\
  {REMOTE_APP}/resources/views/communications/monitoring/partials/wallboard.blade.php \\
  {REMOTE_APP}/resources/css/app.css | head -35
""",
            check=False,
        )
    )
    ssh.close()
    print("Call Monitoring UI order deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
