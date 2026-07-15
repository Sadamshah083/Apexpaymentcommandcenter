#!/usr/bin/env python3
"""Deploy hangup abort fix, matched timers, Turbo progressBarDelay."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/app.js",
    "resources/js/communications-webphone.js",
    "resources/js/call-monitoring.js",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Services/Communications/MorpheusCallEventService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-hangup-timer.log 2>&1
tail -n 20 /tmp/vite-hangup-timer.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
grep -n "progressBarDelay\\|Morpheus hangup request timed out\\|Keep the earliest epoch\\|Match dialer" \\
  {REMOTE_APP}/resources/js/app.js \\
  {REMOTE_APP}/resources/js/communications-webphone.js \\
  {REMOTE_APP}/resources/js/call-monitoring.js \\
  {REMOTE_APP}/app/Services/Communications/MorpheusCallEventService.php | head -30
""",
            check=False,
        )
    )
    ssh.close()
    print("Deployed. Ctrl+F5 dialer + Call Monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
