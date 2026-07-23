#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run, upload_files

ssh = connect()
try:
    upload_files(ssh, [
        (ROOT / "app/Services/Pipeline/CampaignKpiService.php", "app/Services/Pipeline/CampaignKpiService.php"),
        (ROOT / "app/Services/Portal/PortalDashboardService.php", "app/Services/Portal/PortalDashboardService.php"),
    ])
    print(sudo_run(ssh, r"""
cd /var/www/apexone
php artisan tinker --execute="
\$w = App\Models\Workspace::query()->first();
\$c = App\Models\LeadCampaign::query()->where('workspace_id', \$w->id)->first();
\$k = app(App\Services\Pipeline\CampaignKpiService::class)->forCampaign(\$w, (int) \$c->id);
echo json_encode(['dials' => \$k['dials'], 'connected' => \$k['connected'], 'rate' => \$k['connect_rate'], 'top' => array_slice(\$k['dispositions'], 0, 3)]);
"
"""))
finally:
    ssh.close()
