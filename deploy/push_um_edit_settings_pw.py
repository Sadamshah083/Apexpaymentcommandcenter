#!/usr/bin/env python3
"""Deploy edit/settings password + assignment dropdowns."""
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
    "app/Http/Controllers/WorkspaceMemberController.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/views/workflows/partials/um-action-icon.blade.php",
    "resources/views/workflows/partials/reset-password-modal.blade.php",
    "resources/js/member-management.js",
    "resources/js/workspace-admin.js",
    "resources/css/app.css",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "php -l app/Http/Controllers/WorkspaceMemberController.php && "
    "./node_modules/.bin/vite build > /tmp/vite-um-edit-pw.log 2>&1 && echo BUILD:$? && "
    "tail -n 5 /tmp/vite-um-edit-pw.log | tr -cd '\\11\\12\\15\\40-\\176' && "
    "chown -R www-data:www-data public/build && "
    "php artisan view:clear && php artisan cache:clear && "
    "grep -n 'reset-password-modal\\|data-um-reset-password-open\\|Select campaign' "
    "resources/views/workflows/workspaces.blade.php "
    "resources/views/workflows/partials/member-row.blade.php "
    "resources/views/workflows/partials/edit-member-modal.blade.php | head -15",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
