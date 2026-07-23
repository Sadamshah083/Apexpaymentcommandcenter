#!/usr/bin/env python3
"""Deploy single Team lead assign dropdown."""

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
    files = [
        "resources/views/workflows/partials/import-modals.blade.php",
    ]
    pairs = [(ROOT / rel, rel) for rel in files]
    ssh = connect()
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run(ssh, f"""
cd {REMOTE_APP}
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
grep -n "Team lead\\|import-assign-team-lead\\|Team Setter\\|assign-leads-team-pick" resources/views/workflows/partials/import-modals.blade.php | head -25
""", check=False))
    ssh.close()
    print("Single Team lead dropdown live.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
