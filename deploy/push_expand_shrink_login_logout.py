#!/usr/bin/env python3
"""Deploy agent expand/shrink + proper login/logout session handling."""

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
    "app/Http/Controllers/WorkspaceAuthController.php",
    "resources/views/layouts/partials/sidebar-shell.blade.php",
    "resources/views/auth/login_admin.blade.php",
    "resources/views/auth/login_portal.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/css/comm-hub-ui-polish.css",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    missing = [rel for rel in FILES if not (ROOT / rel).is_file()]
    if missing:
        print("Missing files:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-expand-login.log 2>&1
tail -n 16 /tmp/vite-expand-login.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
php artisan view:clear >/dev/null 2>&1 || true
php artisan optimize:clear >/dev/null 2>&1 || true
grep -n "Agent session on admin\|Admin or inactive session\|data-imports-table-size-toggle\|syncFiltersSizeUi\|is-shrunk" \
  app/Http/Controllers/WorkspaceAuthController.php \
  resources/views/layouts/partials/sidebar-shell.blade.php \
  resources/views/communications/partials/center-dialer-hub.blade.php \
  resources/views/admin/dashboard/partials/imports-panel.blade.php \
  resources/js/communications-auto-dial.js | head -30
""",
            check=False,
        )
        print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
