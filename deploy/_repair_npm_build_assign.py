#!/usr/bin/env python3
"""Repair node_modules and rebuild assets on production."""

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

FILES = [
    "resources/css/app.css",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)
    print("npm install + build (this may take a few minutes)...")
    out = sudo_run(
        ssh,
        f"""
cd {REMOTE_APP}
npm install --no-fund --no-audit > /tmp/npm-install-assign.log 2>&1
echo NPM_INSTALL:$?
tail -n 30 /tmp/npm-install-assign.log
test -x node_modules/.bin/vite && echo VITE_OK=yes || echo VITE_OK=no
npm run build > /tmp/vite-assign-fix-final.log 2>&1
echo BUILD:$?
tail -n 35 /tmp/vite-assign-fix-final.log
chown -R www-data:www-data {REMOTE_APP}/public/build {REMOTE_APP}/node_modules
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan cache:clear
grep -c assign-leads-team-pick public/build/assets/app-*.css || true
grep -n 'id=\"import-assign-team-setter\"' resources/views/workflows/partials/import-modals.blade.php | head -5
""",
        check=False,
    )
    print(out)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
