#!/usr/bin/env python3
"""Probe production restore options (no fail on missing git)."""

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

from deploy._ssh import connect, sudo_run_batch


def main() -> int:
    ssh = connect()
    print(sudo_run_batch(ssh, [
        "set +e; cd /var/www/apexone; echo PWD:$(pwd); ls -la | head -25",
        "ls -la /var/www/ 2>&1 | head -40",
        "ls -la /home/ateg/ 2>&1 | head -50",
        "find /var/www /home/ateg /root /tmp -maxdepth 3 \\( -iname '*backup*' -o -iname '*apex*.tar*' -o -iname '*apex*.tgz*' -o -iname 'apexone-*' -o -iname '*.bundle' \\) 2>/dev/null | head -60",
        "stat -c '%y %n' /var/www/apexone/resources/views/workflows/partials/import-modals.blade.php /var/www/apexone/resources/js/app.js /var/www/apexone/resources/css/app.css /var/www/apexone/resources/js/communications-dialer.js 2>&1",
        "find /var/www/apexone/app /var/www/apexone/resources /var/www/apexone/routes /var/www/apexone/config -type f -newermt '2026-07-20 13:00:00' 2>/dev/null | wc -l",
        "find /var/www/apexone/app /var/www/apexone/resources /var/www/apexone/routes /var/www/apexone/config -type f -newermt '2026-07-20 13:00:00' 2>/dev/null | head -100",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
