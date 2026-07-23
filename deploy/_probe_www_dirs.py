#!/usr/bin/env python3
"""Inspect /var/www alternate apexone dirs for restore snapshot."""

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
        "ls -la --quoting-style=c /var/www/",
        "find /var/www -maxdepth 2 -type d -printf '%TY-%Tm-%Td %TH:%TM %p\\n' 2>/dev/null | sort",
        "echo COUNT_BEFORE; find /var/www/apexone/app /var/www/apexone/resources /var/www/apexone/routes /var/www/apexone/config -type f ! -newermt '2026-07-20 13:00:00' 2>/dev/null | wc -l",
        "echo COUNT_AFTER; find /var/www/apexone/app /var/www/apexone/resources /var/www/apexone/routes /var/www/apexone/config -type f -newermt '2026-07-20 13:00:00' 2>/dev/null | wc -l",
        "find / -xdev \\( -name 'apexone*.tar*' -o -name '*apexone*bak*' -o -name 'apexone-backup*' \\) 2>/dev/null | head -20",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
