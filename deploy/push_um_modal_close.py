#!/usr/bin/env python3
"""Deploy modal close-on-save + responsive popups."""
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
    "resources/js/member-management.js",
    "resources/css/app.css",
]

ssh = connect()
upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root="/var/www/apexone")
out = sudo_run(
    ssh,
    "cd /var/www/apexone && "
    "./node_modules/.bin/vite build > /tmp/vite-um-modal-close.log 2>&1 && echo BUILD:$? && "
    "tail -n 5 /tmp/vite-um-modal-close.log | tr -cd '\\11\\12\\15\\40-\\176' && "
    "chown -R www-data:www-data public/build && "
    "php artisan view:clear && php artisan cache:clear && "
    "grep -n 'closeAllMemberModals\\|Saving' resources/js/member-management.js | head -10",
)
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
print("DONE")
