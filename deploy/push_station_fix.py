#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/MorpheusCallEventService.php",
    "resources/js/call-monitoring.js",
    "deploy/_probe_station_fix.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / r, r) for r in FILES], app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1
tail -n 12 /tmp/vite-build.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php deploy/_probe_station_fix.php
php -l {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
