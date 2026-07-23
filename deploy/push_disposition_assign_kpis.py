#!/usr/bin/env python3
"""Deploy disposition race fix + lead visibility + assign-select + campaign KPIs."""
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
    "resources/js/communications-webphone.js",
    "resources/views/communications/partials/center-dialer-hub.blade.php",
    "app/Services/Communications/CommunicationsInboxService.php",
    "app/Http/Controllers/CommunicationsHubController.php",
    "app/Services/Pipeline/SetterDistributionService.php",
    "app/Http/Controllers/PipelineController.php",
    "resources/views/pipeline/partials/leads-table.blade.php",
    "resources/views/pipeline/partials/assign-leads-modal.blade.php",
    "resources/views/pipeline/setter-team/index.blade.php",
    "resources/views/pipeline/closer-team/index.blade.php",
    "resources/views/pipeline/partials/campaigns-overview.blade.php",
    "app/Services/Pipeline/CampaignKpiService.php",
    "app/Http/Controllers/CampaignController.php",
    "app/Http/Controllers/AdminDashboardController.php",
    "app/Services/Portal/PortalDashboardService.php",
    "resources/views/campaigns/show.blade.php",
    "resources/views/campaigns/index.blade.php",
    "resources/views/admin/dashboard/partials/campaigns-panel.blade.php",
    "resources/views/admin/dashboard/index.blade.php",
]


def main() -> int:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
grep -n 'matchesArmedDial\\|setArmedDial\\|assignSelectedLeadsToSetter\\|CampaignKpiService' \\
  resources/js/communications-auto-dial.js \\
  app/Services/Pipeline/SetterDistributionService.php \\
  app/Services/Pipeline/CampaignKpiService.php 2>/dev/null | head -30
php -l app/Services/Pipeline/CampaignKpiService.php
php -l app/Http/Controllers/CampaignController.php
php -l app/Http/Controllers/PipelineController.php
php -l app/Http/Controllers/AdminDashboardController.php
php artisan view:clear
php artisan route:clear
npm run build > /tmp/vite-bundle-fix.log 2>&1
echo BUILD:$?
tail -n 12 /tmp/vite-bundle-fix.log | tr -cd '\\11\\12\\15\\40-\\176'
ls -lt public/build/assets/communications-auto-dial-*.js | head -2
chown -R www-data:www-data storage bootstrap/cache public/build 2>/dev/null || true
""",
        )
        print(out)
        print("Deployed disposition + assign-select + campaign KPIs. Hard refresh Ctrl+F5.")
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
