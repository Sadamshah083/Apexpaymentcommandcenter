#!/usr/bin/env python3
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

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"""
cd {m.REMOTE_APP}
ls -la deploy/_reset_passwords_123456.php
sudo -u www-data php deploy/_reset_passwords_123456.php 2>&1 | tee /tmp/pw-reset.log | tail -n 40
echo EXIT:$?
wc -l /tmp/pw-reset.log
""", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
