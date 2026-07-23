#!/usr/bin/env python3
"""Deploy read-only role/campaign/team columns — edit via Edit modal only."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as ssh_mod

ssh_mod.HOST = "203.215.161.236"
ssh_mod.USER = "ateg"
ssh_mod.PASSWORD = "balitech1"
ssh_mod.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

FILES = [
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/css/app.css",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "php artisan view:clear && php artisan cache:clear && "
    "grep -n 'um-team-cell-form\\|data-member-campaign-select\\|um-role-cell-form' "
    "resources/views/workflows/partials/member-row.blade.php || echo 'inline_editors_removed' && "
    "grep -n 'data-member-role\\|From team lead\\|Own team' "
    "resources/views/workflows/partials/member-row.blade.php | head -10",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
