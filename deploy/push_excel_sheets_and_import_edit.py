#!/usr/bin/env python3
"""Deploy Excel Sheets + import edit API + assign modal scroll + CID lock."""

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
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/MorpheusHubController.php",
    "app/Services/Communications/CommunicationsAgentService.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Integrations/ZoomApiService.php",
    "config/admin_modules.php",
    "config/portal_modules.php",
    "routes/web.php",
    "resources/css/app.css",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "resources/views/layouts/partials/sidebar-nav-portal.blade.php",
    "resources/views/excel-sheets/index.blade.php",
    "resources/views/excel-sheets/portal.blade.php",
    "resources/views/excel-sheets/show.blade.php",
    "resources/views/excel-sheets/portal-show.blade.php",
    "resources/views/excel-sheets/partials/table.blade.php",
    "resources/views/excel-sheets/partials/show-body.blade.php",
]


def main() -> None:
    pairs = []
    for rel in FILES:
        local = ROOT / rel
        if not local.exists():
            raise SystemExit(f"Missing local file: {rel}")
        pairs.append((local, rel.replace("\\", "/")))

    ssh = connect()
    try:
        print(f"Uploading {len(pairs)} files…")
        upload_files(ssh, pairs)
        print("Building assets + clearing caches…")
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan route:clear",
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && npm run build",
                f"cd {REMOTE_APP} && php artisan view:cache",
                f"cd {REMOTE_APP} && php artisan route:cache",
            ],
        )
        print("Verifying routes…")
        out = sudo_run(
            ssh,
            f"cd {REMOTE_APP} && php artisan route:list --name=excel-sheets --columns=method,uri,name",
        )
        print(out)
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
