#!/usr/bin/env python3
"""Deploy auto-dial disposition open-on-hangup fix."""
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

ssh = connect()
try:
    upload_files(ssh, [
        (ROOT / "resources/js/communications-auto-dial.js", "resources/js/communications-auto-dial.js"),
    ])
    print(sudo_run(ssh, r"""
cd /var/www/apexone
grep -n 'suppressMs = state.mode === .auto. ? 2500\|Phone match — primary\|suppressCallEndedUntilActive = false' resources/js/communications-auto-dial.js | head -15
npm run build > /tmp/vite-autodial-open.log 2>&1
echo BUILD:$?
tail -n 8 /tmp/vite-autodial-open.log | tr -cd '\11\12\15\40-\176'
ls -lt public/build/assets/communications-auto-dial-*.js | head -2
chown -R www-data:www-data public/build 2>/dev/null || true
"""))
    print("Fixed: disposition opens again on auto-dial hangup. Ctrl+F5.")
finally:
    ssh.close()
