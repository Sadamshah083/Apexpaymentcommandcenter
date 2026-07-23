#!/usr/bin/env python3
"""Assign DIDs/extensions to Ryan + Tomhanderson closers."""
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


def main() -> int:
    ssh = connect()
    try:
        upload_files(
            ssh,
            [(ROOT / "scripts/assign_ryan_tom_dids.php", "scripts/assign_ryan_tom_dids.php")],
            app_root=REMOTE_APP,
        )
        out = sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/assign_ryan_tom_dids.php",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
