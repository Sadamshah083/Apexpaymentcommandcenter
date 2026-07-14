#!/usr/bin/env python3
"""Deploy B2B role labels + edit popup assignment fields + delete confirm."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "config/sales_ops.php",
    "app/Http/Controllers/WorkspaceMemberController.php",
    "resources/views/workflows/workspaces.blade.php",
    "resources/views/workflows/partials/member-row.blade.php",
    "resources/views/workflows/partials/edit-member-modal.blade.php",
    "resources/views/workflows/partials/add-member-modal.blade.php",
    "resources/js/member-management.js",
    "resources/css/app.css",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP} && npm run build > /tmp/vite-edit-popup.log 2>&1
tail -n 10 /tmp/vite-edit-popup.log
echo BUILD:$?
chown -R www-data:www-data {REMOTE_APP}/public/build
cd {REMOTE_APP} && sudo -u www-data php artisan config:clear
cd {REMOTE_APP} && sudo -u www-data php artisan view:clear
cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear
grep -n "B2B Fronter Team Lead\\|data-edit-member-role\\|Delete permanently\\|syncEditAssignmentFields" \\
  {REMOTE_APP}/config/sales_ops.php \\
  {REMOTE_APP}/resources/views/workflows/partials/edit-member-modal.blade.php \\
  {REMOTE_APP}/resources/js/member-management.js | head -25
""",
            check=False,
        )
    )
    ssh.close()
    print("Edit popup + B2B labels deployed. Ctrl+F5 User Management.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
