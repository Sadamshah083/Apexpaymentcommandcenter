#!/usr/bin/env python3
"""Move setters to ApexPayments and delete ApexOne workspace only."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files


def main() -> int:
    ssh = connect()
    try:
        upload_files(
            ssh,
            [
                (ROOT / "scripts/move_setters_to_apexpayments.php", "scripts/move_setters_to_apexpayments.php"),
                (ROOT / "scripts/upsert_appointment_setters.php", "scripts/upsert_appointment_setters.php"),
            ],
            app_root=REMOTE_APP,
        )
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/move_setters_to_apexpayments.php",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
