#!/usr/bin/env python3
"""Smoke-check Dashboard KPIs + disposition markers on prod."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run


def main() -> int:
    ssh = connect()
    try:
        out = sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
echo '=== SIDEBAR ==='
grep -n 'label="Dashboard"' resources/views/layouts/partials/sidebar-nav-admin.blade.php || true
echo '=== DASH HERO ==='
grep -n 'Total calls for today\\|admin-dash-hero-kpis\\|ops-total-calls-today' resources/views/admin/dashboard/index.blade.php | head -10
echo '=== SERVICE ==='
grep -n 'total_calls_today\\|connected_today\\|dispositioned_today' app/Services/Portal/PortalDashboardService.php | head -10
echo '=== DISPOSITION ==='
grep -n 'suppressMs\\|AbortSignal.timeout\\|matchesArmedDial\\|dispositionHangupConsumed' resources/js/communications-auto-dial.js | head -20
echo '=== KPI QUERY ==='
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$w = App\\Models\\Workspace::query()->first();
if (!$w) {{ echo "no-workspace\\n"; exit(0); }}
$s = app(App\\Services\\Portal\\PortalDashboardService::class)->adminOperationalSummary($w);
echo json_encode([
  "workspace_id" => $w->id,
  "total_calls_today" => $s["total_calls_today"] ?? null,
  "connected_today" => $s["connected_today"] ?? null,
  "dispositioned_today" => $s["dispositioned_today"] ?? null,
], JSON_PRETTY_PRINT), "\\n";
'
""",
        )
        print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
