#!/usr/bin/env python3
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
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$tz = config("app.timezone");
$start = now()->startOfDay();
$end = now()->endOfDay();
echo "tz=$tz start=$start end=$end\\n";
$rows = DB::table("communication_call_logs")
  ->selectRaw("workspace_id, count(*) as c")
  ->where(function ($q) use ($start, $end) {{
      $q->whereBetween("started_at", [$start, $end])
        ->orWhere(function ($f) use ($start, $end) {{
            $f->whereNull("started_at")->whereBetween("created_at", [$start, $end]);
        }});
  }})
  ->groupBy("workspace_id")
  ->orderByDesc("c")
  ->limit(10)
  ->get();
foreach ($rows as $r) echo "ws={{$r->workspace_id}} calls={{$r->c}}\\n";
$svc = app(App\\Services\\Portal\\PortalDashboardService::class);
foreach (App\\Models\\Workspace::query()->orderBy("id")->limit(5)->get() as $w) {{
  $s = $svc->adminOperationalSummary($w);
  echo "summary ws={{$w->id}} name={{$w->name}} total={{$s[\"total_calls_today\"]}} connected={{$s[\"connected_today\"]}} dispo={{$s[\"dispositioned_today\"]}}\\n";
}}
'
""",
        )
        print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
