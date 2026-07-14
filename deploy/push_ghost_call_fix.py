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
    "app/Http/Controllers/MorpheusHubController.php",
    "deploy/_clear_ghost_calls.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / r, r) for r in FILES], app_root=REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
php -l {REMOTE_APP}/app/Services/Communications/MorpheusCallEventService.php
php -l {REMOTE_APP}/app/Services/Communications/CallMonitoringService.php
php -l {REMOTE_APP}/app/Http/Controllers/MorpheusHubController.php
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
cd {REMOTE_APP} && sudo -u www-data php deploy/_clear_ghost_calls.php
""",
            check=False,
        )
    )
    ssh.close()
    print("Ghost calls cleared. Ctrl+F5 Call Monitoring.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
