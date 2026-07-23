#!/usr/bin/env python3
"""Deploy auto-dial disposition Save & Next race fix."""
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
    "resources/js/communications-auto-dial.js",
    "resources/js/communications-webphone.js",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
grep -n 'handledHangupUuids\\|Save & Next the lock\\|NEVER reset hangup-dispatch' resources/js/communications-auto-dial.js | head -20
npm run build > /tmp/vite-autodial-dispo.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-autodial-dispo.log | tr -cd '\\11\\12\\15\\40-\\176'
ls -lt public/build/assets/communications-auto-dial-*.js | head -2
chown -R www-data:www-data {REMOTE_APP}/public/build
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        print("Auto-dial disposition fix deployed. Hard refresh Ctrl+F5.")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
