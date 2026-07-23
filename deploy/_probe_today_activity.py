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
$tz = config("app.business_timezone", "America/New_York");
$start = now($tz)->startOfDay();
$end = now($tz)->endOfDay();
echo "bounds $start $end\\n";
echo "lead_activities_today=" . DB::table("lead_activities")->whereBetween("created_at", [$start, $end])->count() . "\\n";
foreach (DB::table("lead_activities")->selectRaw("type, count(*) as c")->whereBetween("created_at", [$start, $end])->groupBy("type")->orderByDesc("c")->limit(20)->get() as $t) {{
  echo "act {{$t->type}}={{$t->c}}\\n";
}}
foreach (DB::table("communication_call_logs")->selectRaw("disposition, count(*) as c")->whereBetween("started_at", [$start, $end])->whereNotNull("disposition")->where("disposition", "!=", "")->groupBy("disposition")->orderByDesc("c")->limit(30)->get() as $d) {{
  echo "dispo {{$d->disposition}}={{$d->c}}\\n";
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
