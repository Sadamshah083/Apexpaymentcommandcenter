#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "routes/morpheus-communications.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "resources/views/communications/partials/webphone-panel.blade.php",
    "resources/js/communications-webphone.js",
    "resources/js/call-monitoring.js",
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
cd {REMOTE_APP} && sudo -u www-data php artisan route:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php artisan route:list --name=calls.ended 2>&1 | head -5
grep -n "calls/ended\\|markCallEnded\\|BOARD_POLL_ACTIVE\\|notifyMonitoringHangup\\|removeEndedCallsFromBoard" \\
  {REMOTE_APP}/routes/morpheus-communications.php \\
  {REMOTE_APP}/resources/js/call-monitoring.js \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php | head -25
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
