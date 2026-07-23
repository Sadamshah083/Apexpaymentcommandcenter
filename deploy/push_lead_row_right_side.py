#!/usr/bin/env python3
"""Deploy: lead row two-column layout (details on the right)."""

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
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-lead-side.log 2>&1
tail -n 12 /tmp/vite-lead-side.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
grep -n "ghl-dialer-lead-side\\|ghl-dialer-lead-body\\|has-side" \\
  resources/views/communications/partials/dialer-lead-row.blade.php \\
  resources/js/communications-auto-dial.js \\
  resources/css/comm-hub-ui-polish.css | head -25
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", errors="replace") + b"\n")
    finally:
        ssh.close()
    print("Lead row right-side layout deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
