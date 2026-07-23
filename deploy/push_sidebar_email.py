#!/usr/bin/env python3
"""Deploy sidebar footer email above Admin + Sign out."""

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
    "resources/css/app.css",
    "resources/views/layouts/partials/sidebar-shell.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(local) for local, _ in pairs if not local.is_file()]
    if missing:
        print("Missing files:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("Uploading sidebar email footer...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-sidebar-email.log 2>&1; echo BUILD:$?; tail -n 20 /tmp/vite-sidebar-email.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
    ]))
    ssh.close()
    print("Sidebar email footer deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
