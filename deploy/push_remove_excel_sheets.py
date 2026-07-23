#!/usr/bin/env python3
"""Remove Excel Sheets feature from production."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "routes/web.php",
    "config/admin_modules.php",
    "config/portal_modules.php",
    "resources/js/app.js",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
]


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    for local, _ in pairs:
        if not local.exists():
            raise SystemExit(f"Missing {local}")

    ssh = connect()
    try:
        print(f"Uploading {len(pairs)} files…")
        upload_files(ssh, pairs)
        print("Removing Excel Sheets files on server…")
        sudo_run(
            ssh,
            f"rm -rf {REMOTE_APP}/resources/views/excel-sheets "
            f"{REMOTE_APP}/app/Http/Controllers/ExcelSheetController.php "
            f"{REMOTE_APP}/app/Models/WorkspaceSpreadsheet.php "
            f"{REMOTE_APP}/resources/js/excel-sheet-editor.js",
            check=False,
        )
        print("Clear caches + rebuild…")
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan route:clear",
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && npm run build",
                f"cd {REMOTE_APP} && php artisan route:cache",
                f"cd {REMOTE_APP} && php artisan view:cache",
                f"cd {REMOTE_APP} && php artisan config:cache",
            ],
        )
        out = sudo_run(ssh, f"cd {REMOTE_APP} && php artisan route:list --name=excel-sheets", check=False)
        print(out or "(no excel-sheets routes)")
        print(sudo_run(ssh, "grep -n 'Excel Sheets' "
            f"{REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-admin.blade.php "
            f"{REMOTE_APP}/resources/views/layouts/partials/sidebar-nav-portal.blade.php "
            "|| echo sidebar_clean"))
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
