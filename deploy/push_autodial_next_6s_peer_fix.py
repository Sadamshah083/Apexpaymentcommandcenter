#!/usr/bin/env python3
"""Deploy: auto-dial next call after hangup (6s) + fix overlapping ringing number."""

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
    "resources/css/comm-hub-ghl-theme.css",
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
npm run build > /tmp/vite-autodial-6s.log 2>&1
tail -n 20 /tmp/vite-autodial-6s.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
grep -n "summaryOpen\\|summaryOwnsUi\\|Next call in\\|is-call-active .ghl-dialer-display" \\
  resources/js/communications-webphone.js \\
  resources/js/communications-auto-dial.js \\
  resources/css/comm-hub-ghl-theme.css | head -40
""",
            check=False,
        )
        print(out)
    finally:
        ssh.close()
    print("Auto-dial 6s next-call + ringing peer fix deployed. Ctrl+F5 dialer.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
