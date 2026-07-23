#!/usr/bin/env python3
"""Deploy file restrict, agent dial totals, dial-pad recent info, scrollable tables."""

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
    "database/migrations/2026_07_21_220000_add_agent_restricted_to_workflows_table.php",
    "app/Models/Workflow.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsCallHistoryService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "routes/web.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-form.blade.php",
    "resources/js/communications-dialer.js",
    "resources/js/pagination-preserve.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
]


def main() -> None:
    pairs = [(ROOT / rel, rel.replace("\\", "/")) for rel in FILES]
    missing = [str(local) for local, _ in pairs if not local.exists()]
    if missing:
        raise SystemExit("Missing files:\n" + "\n".join(missing))

    ssh = connect()
    try:
        upload_files(ssh, pairs)
        sudo_run_batch(
            ssh,
            [
                f"cd {REMOTE_APP} && php artisan migrate --force --no-interaction",
                f"cd {REMOTE_APP} && php artisan view:clear",
                f"cd {REMOTE_APP} && php artisan route:clear",
                f"cd {REMOTE_APP} && php artisan cache:clear",
                f"cd {REMOTE_APP} && php artisan config:clear",
                # Rebuild frontend assets (dialer + pagination + CSS)
                f"cd {REMOTE_APP} && (command -v npm >/dev/null && npm run build || true)",
                f"cd {REMOTE_APP} && php artisan opcache:clear 2>/dev/null || true",
                "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true",
            ],
            check=False,
        )
        print("Done.")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
