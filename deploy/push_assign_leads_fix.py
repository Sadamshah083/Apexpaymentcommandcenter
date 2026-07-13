#!/usr/bin/env python3
"""Deploy assign-leads and unassigned workflow fixes."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Models/WorkflowLead.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "app/Services/Pipeline/CampaignBatchService.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Services/Workflow/WorkflowService.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/workflows/partials/assign-to-team.blade.php",
    "resources/views/workflows/show.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/css/app.css",
    "tests/Feature/ApexPaymentsPipelineTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print("Building frontend assets...")
    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && npm run build",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan route:clear",
    ])
    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")
    print(sudo_run(ssh, f"curl -fsS http://203.215.160.44/admin/login -o /dev/null -w '%{{http_code}}'"))
    ssh.close()
    print("Unassigned leads fix deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
