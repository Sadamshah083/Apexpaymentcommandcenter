#!/usr/bin/env python3
"""Deploy B2B Closer import assign support (campaign-first TL + closer distribution)."""

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

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Support/WorkflowAssignmentRoles.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files to {m.HOST}...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Clearing caches...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, "curl -fsS https://crm.apexonepayments.com/admin/login -o /dev/null -w '%{http_code}'", check=False))
    ssh.close()
    print("Closer import assign deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
