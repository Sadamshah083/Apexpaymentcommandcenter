#!/usr/bin/env python3
"""Deploy call-log recording cache + start-from-zero play fix."""

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

from deploy._ssh import connect, sudo_run, upload_files

FILES = [
    "resources/views/communications/agent-status/partials/panel.blade.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
        out = sudo_run(
            ssh,
            "cd /var/www/apexone && php artisan view:clear && php artisan cache:clear && echo REC_PLAY_OK",
        )
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
