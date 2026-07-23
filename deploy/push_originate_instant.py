#!/usr/bin/env python3
"""Deploy instant originate on click (no 1.5s prepare delay when Registered)."""
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
    "resources/js/communications-dialer.js",
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
grep -n 'Fast path\\|originateFetch\\|Already Registered\\|lineReady' resources/js/communications-dialer.js resources/js/communications-webphone.js | head -20
npm run build > /tmp/vite-originate-fast.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-originate-fast.log | tr -cd '\\11\\12\\15\\40-\\176'
ls -lt public/build/assets/communications-*.js | head -4
chown -R www-data:www-data public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed instant originate. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
