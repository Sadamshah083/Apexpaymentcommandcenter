#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/MorpheusCallEventService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "resources/js/call-monitoring.js",
    "resources/js/communications-webphone.js",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / r, r) for r in FILES], REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1
tail -n 12 /tmp/vite-build.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
grep -n 'connectedIdleSec: 0' {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php
grep -n 'prefer client connected_at\\|Dialer connected timer' {REMOTE_APP}/app/Services/Communications/MorpheusCallEventService.php | head -3
grep -n 'BOARD_POLL_MS = 2500\\|agent-sync\\|heartbeat' {REMOTE_APP}/resources/js/call-monitoring.js {REMOTE_APP}/resources/js/communications-webphone.js | head -15
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
