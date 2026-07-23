#!/usr/bin/env python3
"""Deploy disposition re-arm + add-account email fix."""
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
    "resources/js/communications-auto-dial.js",
    "resources/js/workspace-admin.js",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "app/Services/Workspace/WorkspaceMemberService.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    try:
        upload_files(ssh, pairs, REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
npm run build > /tmp/vite-dispo-email.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-dispo-email.log | tr -cd '\\11\\12\\15\\40-\\176'
echo ---
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan optimize:clear
chown -R www-data:www-data {REMOTE_APP}/public/build
echo ---
grep -n create-email resources/views/workflows/partials/add-member-modal.blade.php | head -5
grep -n \"'email' => 'required\" app/Http/Controllers/WorkspaceMemberController.php | head -5
grep -n armDispositionForNewCall resources/js/communications-auto-dial.js | head -10
grep -n 'clearClosedDisposition\\|until = Date.now() + 12000' resources/js/communications-auto-dial.js | head -10
""",
            check=False,
        )
        sys.stdout.buffer.write((out or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        print("Deployed disposition re-arm + add-account email. Hard refresh (Ctrl+F5).")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
