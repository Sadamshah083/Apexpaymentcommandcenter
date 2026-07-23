#!/usr/bin/env python3
"""Deploy role rename + create appointment setter accounts under Jacob."""

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

FILES = [
    "config/sales_ops.php",
    "resources/views/admin/dashboard/index.blade.php",
    "scripts/upsert_appointment_setters.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/upsert_appointment_setters.php",
            f"grep -n 'Appointment Setter' {REMOTE_APP}/config/sales_ops.php | head -5",
            f"grep -n Fronter {REMOTE_APP}/config/sales_ops.php || echo NO_FRONTER_LABEL",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
