#!/usr/bin/env python3
"""Deploy presence debounce + disposition harden + close call-events WS."""
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

VERIFY = r"""
cd /var/www/apexone
# Sanity: new helpers exist
grep -n 'flushPresenceHeartbeat\|presenceFlushTimer\|Do NOT include call_ended' resources/js/communications-auto-dial.js | head -10
grep -n 'uniqueSockets\|stopCallEventsStream' resources/js/communications-webphone.js | head -8
grep -n 'Never re-arm disposition from call-active' resources/js/communications-auto-dial.js | head -3
npm run build > /tmp/vite-presence-debounce.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-presence-debounce.log | tr -cd '\11\12\15\40-\176'
ls -lt public/build/assets/communications-auto-dial-*.js public/build/assets/communications-*.js 2>/dev/null | head -8
# Confirm new hash is not the old i8FK-Mp3 only
grep -l flushPresenceHeartbeat public/build/assets/communications-auto-dial-*.js | head -3
chown -R www-data:www-data /var/www/apexone/public/build
"""


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(ssh, VERIFY, check=False)
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        print("DONE — hard refresh Ctrl+F5 required.")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
