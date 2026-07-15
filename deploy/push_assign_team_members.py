#!/usr/bin/env python3
"""Deploy assign modal team members list."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Support/WorkflowAssignmentRoles.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "resources/views/workflows/partials/import-modals.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/workflows/partials/assign-to-team.blade.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, REMOTE_APP)
    print(
        sudo_run(
            ssh,
            f"""
php -l {REMOTE_APP}/app/Support/WorkflowAssignmentRoles.php
php -l {REMOTE_APP}/app/Http/Controllers/WorkflowController.php
php -l {REMOTE_APP}/app/Services/Pipeline/SetterDistributionService.php
""",
            check=False,
        )
    )
    sudo_run_batch(
        ssh,
        [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear",
        ],
    )
    print(
        sudo_run(
            ssh,
            f"grep -n 'import-assign-members\\|setterTeamMemberMap\\|member_ids' "
            f"{REMOTE_APP}/resources/views/workflows/partials/import-modals.blade.php "
            f"{REMOTE_APP}/app/Http/Controllers/WorkflowController.php | head -25",
            check=False,
        )
    )
    ssh.close()
    print("Assign team members UI deployed. Ctrl+F5.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
