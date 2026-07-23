#!/usr/bin/env python3
"""Deploy All call logs recording loader + fast stream to NEW domain server."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(p) for p, _ in pairs if not p.is_file()]
    if missing:
        print("Missing:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("1) Upload files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("2) Build CSS + clear caches...")
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-rec-loader.log 2>&1; echo BUILD:$?; tail -n 15 /tmp/vite-rec-loader.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
    ], check=False))

    ssh.close()
    print("Done. Hard refresh All call logs (Ctrl+F5) then click Play.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
