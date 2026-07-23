#!/usr/bin/env python3
"""Deploy upload-only (AI unchecked) import UX + ready-to-assign for stored leads."""
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
    ("app/Support/WorkflowStatusLabel.php", "app/Support/WorkflowStatusLabel.php"),
    ("app/Models/WorkflowLead.php", "app/Models/WorkflowLead.php"),
    ("app/Jobs/ProcessWorkflowJob.php", "app/Jobs/ProcessWorkflowJob.php"),
    ("app/Http/Controllers/WorkflowController.php", "app/Http/Controllers/WorkflowController.php"),
    ("app/Services/Workspace/WorkspaceSyncService.php", "app/Services/Workspace/WorkspaceSyncService.php"),
    ("resources/views/components/workflow-status-pill.blade.php", "resources/views/components/workflow-status-pill.blade.php"),
    ("resources/views/workflows/show.blade.php", "resources/views/workflows/show.blade.php"),
    ("resources/views/admin/dashboard/partials/imports-panel.blade.php", "resources/views/admin/dashboard/partials/imports-panel.blade.php"),
    ("resources/js/workspace-sync.js", "resources/js/workspace-sync.js"),
    ("resources/css/app.css", "resources/css/app.css"),
]

ssh = connect()
try:
    pairs = [(ROOT / local, remote) for local, remote in FILES]
    print("=== upload ===")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("=== build + clear ===")
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data npm run build 2>&1 | tail -n 40",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan queue:restart || true",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
    print("DONE")
finally:
    ssh.close()
