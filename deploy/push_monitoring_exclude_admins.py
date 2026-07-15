#!/usr/bin/env python3
"""Deploy Call Monitoring filter: exclude Super Admin / Admin."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "app/Services/Communications/AgentPresenceService.php",
    "app/Services/Communications/CallMonitoringService.php",
    "app/Http/Controllers/CallMonitoringController.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
php -l {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php
php -l {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php
php -l {REMOTE_APP}/app/Http/Controllers/CallMonitoringController.php
cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear >/tmp/mon-clear.log 2>&1
tail -n 8 /tmp/mon-clear.log
grep -n "isExcludedFromMonitoring\\|EXCLUDED_ROLES\\|monitoringExcludedExtensions\\|rejectExcludedMonitoringRows" \\
  {REMOTE_APP}/app/Services/Communications/AgentPresenceService.php \\
  {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php \\
  {REMOTE_APP}/app/Http/Controllers/CallMonitoringController.php | head -40
""",
            check=False,
        )
    )
    ssh.close()
    print("Call Monitoring admin exclusion deployed. Ctrl+F5 monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
