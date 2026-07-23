#!/usr/bin/env python3
"""Deploy state-from-area-code, Gatekeeper disposition, file checkboxes, 10h session."""

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
    "app/Support/UsAreaCodeState.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Http/Controllers/AdminDashboardController.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "config/integrations.php",
    "resources/views/communications/partials/call-summary-modal.blade.php",
    "resources/views/admin/dashboard/partials/imports-panel.blade.php",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/js/communications-auto-dial.js",
    "resources/css/app.css",
    "resources/css/comm-hub-ui-polish.css",
    "tests/Unit/Support/UsAreaCodeStateTest.php",
]


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    missing = [str(local) for local, _ in pairs if not local.is_file()]
    if missing:
        print("Missing files:")
        for path in missing:
            print(f"  - {path}")
        return 1

    ssh = connect()
    print("Uploading lead state / gatekeeper / file checks / session...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    print(sudo_run_batch(ssh, [
        # Keep sessions alive for 10 hours
        f"cd {REMOTE_APP} && (grep -q '^SESSION_LIFETIME=' .env && sed -i 's/^SESSION_LIFETIME=.*/SESSION_LIFETIME=600/' .env || echo 'SESSION_LIFETIME=600' >> .env)",
        f"cd {REMOTE_APP} && npm run build > /tmp/vite-lead-state-files.log 2>&1; echo BUILD:$?; tail -n 25 /tmp/vite-lead-state-files.log",
        f"chown -R www-data:www-data {REMOTE_APP}/public/build",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan test --filter=UsAreaCodeStateTest",
    ]))
    ssh.close()
    print("Deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
