#!/usr/bin/env python3
from __future__ import annotations
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
from deploy._ssh import connect, sudo_run

ssh = connect()
cmds = [
    "ls -t /var/www/apexone/public/build/assets/communications-webphone-*.js 2>/dev/null | head -1",
    "grep -c onNotify /var/www/apexone/public/build/assets/communications-webphone-*.js 2>/dev/null | head -5",
    "grep -c '481' /var/www/apexone/public/build/assets/communications-webphone-*.js 2>/dev/null | head -3",
]
for c in cmds:
    print(">", c)
    print(sudo_run(ssh, c, check=False))
    print()
ssh.close()
