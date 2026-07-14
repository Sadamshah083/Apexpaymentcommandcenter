#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "resources/js/call-monitoring.js",
    "resources/js/app.js",
    "resources/css/app.css",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / r, r) for r in FILES], app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-build.log 2>&1
tail -n 18 /tmp/vite-build.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
grep -n 'listLocalExtensionDirectory' {REMOTE_APP}/app/Services/Communications/CommunicationsAgentService.php | head -2
grep -n 'listLocalExtensionDirectory' {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php | head -2
grep -n 'needsCallSummary\\|roleLabelMemory' {REMOTE_APP}/resources/js/app.js {REMOTE_APP}/resources/js/call-monitoring.js | head -10
""",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
