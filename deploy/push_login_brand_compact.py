#!/usr/bin/env python3
"""Deploy compact admin login brand."""

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

FILES = ["resources/views/auth/login_admin.blade.php"]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ]))
    ssh.close()
    print("Compact login brand deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
