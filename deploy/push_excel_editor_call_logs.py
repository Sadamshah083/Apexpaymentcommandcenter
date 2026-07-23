#!/usr/bin/env python3
"""Deploy editable Excel Sheets + All call logs recordings."""

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
    "app/Http/Controllers/ExcelSheetController.php",
    "app/Http/Controllers/AgentStatusReportController.php",
    "app/Models/WorkspaceSpreadsheet.php",
    "app/Services/Communications/AgentStatusReportService.php",
    "app/Services/Communications/CommunicationsDataService.php",
    "database/migrations/2026_07_16_210000_create_workspace_spreadsheets_table.php",
    "routes/web.php",
    "resources/js/app.js",
    "resources/js/excel-sheet-editor.js",
    "resources/css/app.css",
    "resources/views/excel-sheets/index.blade.php",
    "resources/views/excel-sheets/portal.blade.php",
    "resources/views/excel-sheets/show.blade.php",
    "resources/views/excel-sheets/portal-show.blade.php",
    "resources/views/excel-sheets/partials/list.blade.php",
    "resources/views/excel-sheets/partials/editor.blade.php",
    "resources/views/communications/agent-status/index.blade.php",
    "resources/views/communications/agent-status/portal.blade.php",
    "resources/views/communications/agent-status/partials/panel.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
]


def main() -> None:
    pairs = []
    for rel in FILES:
        local = ROOT / rel
        if not local.exists():
            raise SystemExit(f"Missing: {rel}")
        pairs.append((local, rel.replace("\\", "/")))

    ssh = connect()
    try:
        print(f"Uploading {len(pairs)} files…")
        upload_files(ssh, pairs)
        # Remove obsolete preview partials if present
        sudo_run(
            ssh,
            f"rm -f {REMOTE_APP}/resources/views/excel-sheets/partials/table.blade.php "
            f"{REMOTE_APP}/resources/views/excel-sheets/partials/show-body.blade.php",
            check=False,
        )
        print("Migrate + build…")
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan migrate --force --no-interaction",
                f"cd {REMOTE_APP} && php artisan route:clear",
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && npm run build",
                f"cd {REMOTE_APP} && php artisan route:cache",
                f"cd {REMOTE_APP} && php artisan view:cache",
            ],
        )
        print(sudo_run(ssh, f"cd {REMOTE_APP} && php artisan route:list --name=excel-sheets"))
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
