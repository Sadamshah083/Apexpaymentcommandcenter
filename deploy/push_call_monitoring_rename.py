#!/usr/bin/env python3
"""Rename Team Lead Status -> Call Monitoring and update URL path."""

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
    "routes/web.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/communications/monitoring/partials/wallboard.blade.php",
    "resources/js/communications-auto-dial.js",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(local) for local, _ in pairs if not local.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("Uploading Call Monitoring rename...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building + clearing caches...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-call-mon-rename.log 2>&1; echo BUILD:$?; tail -n 20 /tmp/vite-call-mon-rename.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:list --path=call-monitoring | head -n 40",
    ]))
    ssh.close()
    print("Call Monitoring rename deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
