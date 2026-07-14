#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files


def main() -> int:
    ssh = connect()
    upload_files(
        ssh,
        [(ROOT / "deploy/_probe_monitoring_fix.php", "deploy/_probe_monitoring_fix.php")],
        app_root=REMOTE_APP,
    )
    print("--- phpunit ---")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data ./vendor/bin/phpunit --filter=CallMonitoringServiceTest 2>&1 | tail -n 60",
            check=False,
        )
    )
    print("--- probe ---")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data php deploy/_probe_monitoring_fix.php 2>&1",
            check=False,
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
