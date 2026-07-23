#!/usr/bin/env python3
"""Deploy disposition timeout fix + lead dial meta/tags + remove monitoring UI."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

FILES = [
    "resources/js/communications-auto-dial.js",
    "resources/views/communications/partials/dialer-lead-row.blade.php",
    "resources/css/comm-hub-ui-polish.css",
    "app/Services/Communications/DialerImportedLeadsService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Models/WorkflowLead.php",
    "app/Models/Workflow.php",
    "app/Http/Controllers/WorkflowController.php",
    "app/Services/Workflow/WorkflowService.php",
    "app/Jobs/ProcessWorkflowJob.php",
    "resources/views/workflows/create.blade.php",
    "database/migrations/2026_07_21_030000_add_lead_disposition_tags_segment.php",
    "resources/views/layouts/partials/sidebar-nav-admin.blade.php",
    "config/admin_modules.php",
    "bootstrap/app.php",
    "routes/web.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php artisan migrate --force --no-interaction
php artisan config:clear
php artisan view:clear
php artisan route:clear
npm run build > /tmp/vite-dispo-timeout.log 2>&1
echo BUILD:$?
tail -n 10 /tmp/vite-dispo-timeout.log | tr -cd '\\11\\12\\15\\40-\\176'
grep -n 'AbortSignal.timeout(25000)\\|wasTimeout\\|Last dial' resources/js/communications-auto-dial.js | head -10
# Stop heavy server/comm monitors (Telescope stays).
systemctl stop apexone-comm-hub-monitor.timer 2>/dev/null || true
systemctl disable apexone-comm-hub-monitor.timer 2>/dev/null || true
systemctl stop apexone-comm-hub-monitor.service 2>/dev/null || true
systemctl disable apexone-comm-hub-monitor.service 2>/dev/null || true
systemctl stop apexone-watchdog.timer 2>/dev/null || true
systemctl disable apexone-watchdog.timer 2>/dev/null || true
echo MONITORS_STOPPED
chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
