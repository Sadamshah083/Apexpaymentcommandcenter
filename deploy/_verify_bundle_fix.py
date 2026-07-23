#!/usr/bin/env python3
"""Verify disposition markers, lead isolation, and campaign KPI wiring on prod."""
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

from deploy._ssh import connect, sudo_run


def main() -> int:
    ssh = connect()
    try:
        print(sudo_run(ssh, r"""
cd /var/www/apexone
echo '=== JS markers ==='
grep -c matchesArmedDial resources/js/communications-auto-dial.js
grep -c _outboundDialToken resources/js/communications-webphone.js
echo '=== Agent files hidden ==='
grep -n 'isAgentDialer && \$fileOptions\|tier === .agent' resources/views/communications/partials/center-dialer-hub.blade.php app/Http/Controllers/CommunicationsHubController.php | head -10
echo '=== Assign selected ==='
grep -n assignSelectedLeadsToSetter app/Services/Pipeline/SetterDistributionService.php | head -3
echo '=== Campaign KPI smoke ==='
php artisan tinker --execute="
\$w = App\Models\Workspace::query()->first();
\$svc = app(App\Services\Pipeline\CampaignKpiService::class);
\$c = App\Models\LeadCampaign::query()->where('workspace_id', \$w->id)->first();
if (!\$c) { echo 'NO_CAMPAIGN'; exit; }
\$k = \$svc->forCampaign(\$w, (int) \$c->id);
echo json_encode(['campaign' => \$c->name, 'kpis' => \$k]);
"
echo
echo '=== Lead ownership sample ==='
php artisan tinker --execute="
\$rows = DB::table('workflow_leads')->whereNotNull('assigned_user_id')->select('assigned_user_id', DB::raw('count(*) as c'))->groupBy('assigned_user_id')->orderByDesc('c')->limit(5)->get();
echo \$rows->toJson();
"
""", check=False))
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
