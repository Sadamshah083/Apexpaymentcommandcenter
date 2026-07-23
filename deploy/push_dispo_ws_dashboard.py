#!/usr/bin/env python3
"""Deploy: instant disposition via WS, skip HTTP hangup on remote end, inbound off, dashboard boxes."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-webphone.js",
    "resources/js/communications-auto-dial.js",
    "resources/views/admin/dashboard/index.blade.php",
    "resources/css/app.css",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
grep -n 'skipHttpHangup\\|allowBridgeHangup\\|Inbound call rejected\\|admin-dash-card--panel' \\
  resources/js/communications-webphone.js \\
  resources/views/admin/dashboard/index.blade.php | head -25
php artisan view:clear
npm run build > /tmp/vite-dispo-ws.log 2>&1
echo BUILD:$?
ls -lt public/build/assets/communications-*.js public/build/assets/app-*.css | head -6
chown -R www-data:www-data public/build storage bootstrap/cache 2>/dev/null || true
""",
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", errors="replace") + b"\n")
        print("Deployed instant disposition + dashboard boxes. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
