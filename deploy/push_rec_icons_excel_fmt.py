#!/usr/bin/env python3
"""Deploy recording icons/close + Excel formatting/autosave."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

FILES = [
    "app/Http/Controllers/ExcelSheetController.php",
    "app/Models/WorkspaceSpreadsheet.php",
    "database/migrations/2026_07_16_213000_add_styles_to_workspace_spreadsheets_table.php",
    "resources/js/excel-sheet-editor.js",
    "resources/css/app.css",
    "resources/views/excel-sheets/partials/editor.blade.php",
    "resources/views/excel-sheets/show.blade.php",
    "resources/views/excel-sheets/portal-show.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
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
        print("Migrate + build…")
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan migrate --force --no-interaction",
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && npm run build",
                f"cd {REMOTE_APP} && php artisan view:cache",
            ],
        )
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
