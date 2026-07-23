#!/usr/bin/env python3
"""Rebuild Vite assets after failed assign deploy."""

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
    "resources/css/app.css",
    "resources/views/workflows/partials/import-modals.blade.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES], app_root=REMOTE_APP)
    print(sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && (command -v npm; ls node_modules/.bin/vite; ls node_modules/vite/bin/vite.js) 2>&1 | head -20",
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-assign-fix2.log 2>&1; echo BUILD:$?; tail -n 40 /tmp/vite-assign-fix2.log",
        f"cd {REMOTE_APP} && npx --yes vite build > /tmp/vite-assign-fix3.log 2>&1; echo NPX:$?; tail -n 40 /tmp/vite-assign-fix3.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"grep -c assign-leads-team-pick {REMOTE_APP}/public/build/assets/app-*.css || true",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
    ]))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
