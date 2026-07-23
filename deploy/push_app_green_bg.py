#!/usr/bin/env python3
"""Deploy light-green app shell background to match login."""

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

FILES = ["resources/css/app.css"]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    ssh = connect()
    print("Uploading CSS...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-green-bg.log 2>&1; echo BUILD:$?; tail -n 20 /tmp/vite-green-bg.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ]))
    ssh.close()
    print("Light-green app background deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
