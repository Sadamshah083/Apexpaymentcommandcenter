#!/usr/bin/env python3
"""Deploy Team Members manage button layout + delete confirm copy."""
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
    "resources/css/app.css",
    "resources/views/workflows/workspaces.blade.php",
    "resources/js/member-management.js",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
print(
    sudo_run(
        ssh,
        "cd /var/www/apexone && "
        "./node_modules/.bin/vite build > /tmp/vite-um-layout.log 2>&1 && echo BUILD:$? && "
        "tail -n 8 /tmp/vite-um-layout.log && "
        "chown -R www-data:www-data public/build && "
        "php artisan view:clear && "
        "grep -n 'um-manage-btn--labeled' resources/css/app.css | head -5 && "
        "grep -n \"Delete account\" resources/js/member-management.js | head -3 && "
        "grep -l 'Delete account' public/build/assets/workspace-features-*.js | head -2",
    )
)
ssh.close()
print("DONE")
