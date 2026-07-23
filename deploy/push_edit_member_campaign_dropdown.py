#!/usr/bin/env python3
"""Deploy Edit account Campaign dropdown for agents + team leads."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/js/member-management.js",
    "app/Services/Workspace/WorkspaceMemberService.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && npm run build > /tmp/vite-edit-campaign.log 2>&1; echo BUILD:$?; tail -n 15 /tmp/vite-edit-campaign.log",
            f"chown -R www-data:www-data {REMOTE_APP}/public/build",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
            f"grep -n 'showCampaign\\|Campaign' {REMOTE_APP}/resources/js/member-management.js | head -10",
            f"grep -n 'data-edit-campaign-field\\|Campaign' {REMOTE_APP}/resources/views/workflows/partials/edit-member-modal.blade.php | head -10",
            f"grep -n 'canAssignCampaign\\|isAgentRole' {REMOTE_APP}/app/Services/Workspace/WorkspaceMemberService.php | head -10",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("LIVE_OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
