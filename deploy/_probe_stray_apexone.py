#!/usr/bin/env python3
"""Inspect the stray /var/www/apexone\\r directory."""

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
        "python3 -c \"import os; print([repr(x) for x in os.listdir('/var/www')])\"",
        "python3 -c \"import os; p=[os.path.join('/var/www',x) for x in os.listdir('/var/www') if x.startswith('apexone') and x!='apexone']; print(p); [print(x, os.listdir(x) if os.path.isdir(x) else 'file') for x in p]\"",
        "true",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
