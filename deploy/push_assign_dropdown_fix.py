#!/usr/bin/env python3
"""Deploy assign dropdown fix + sidebar updates."""

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
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
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
    print("Uploading assign + sidebar fix...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-assign-fix.log 2>&1; echo BUILD:$?; tail -n 25 /tmp/vite-assign-fix.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"grep -n 'import-assign-team-setter\\|data-pretty-select\\|Team Setter' {REMOTE_APP}/resources/views/workflows/partials/import-modals.blade.php | head -20",
    ]))
    ssh.close()
    print("Assign + sidebar fix live.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
