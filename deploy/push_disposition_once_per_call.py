#!/usr/bin/env python3
"""Deploy disposition once-per-call arming fix."""
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
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    out = sudo_run(
        ssh,
        f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-disposition-once.log 2>&1
echo BUILD:$?
tail -n 15 /tmp/vite-disposition-once.log | tr -cd '\\11\\12\\15\\40-\\176'
echo ---
grep -n dispositionArmed {REMOTE_APP}/resources/js/communications-auto-dial.js | head -20
grep -n 'Never reset' {REMOTE_APP}/resources/js/communications-auto-dial.js | head -5
chown -R www-data:www-data {REMOTE_APP}/public/build
""",
        check=False,
    )
    sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
    sys.stdout.buffer.write(b"\n")
    ssh.close()
    print("Disposition once-per-call fix deployed. Agents: hard refresh (Ctrl+F5) the dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
