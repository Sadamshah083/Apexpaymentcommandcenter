#!/usr/bin/env python3
"""Deploy beautiful responsive dropdowns (admin + agent)."""

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
    "resources/js/pretty-select.js",
    "resources/js/app.js",
    "resources/js/member-management.js",
    "resources/css/app.css",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
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
    print("Uploading pretty dropdown fixes...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building assets...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-pretty-dd.log 2>&1; echo BUILD:$?; tail -n 30 /tmp/vite-pretty-dd.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
    ]))
    ssh.close()
    print("Pretty responsive dropdowns deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
