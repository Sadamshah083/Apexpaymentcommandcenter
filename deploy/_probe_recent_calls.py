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
echo "count_all=" . DB::table("communication_call_logs")->count() . "\\n";
echo "count_24h=" . DB::table("communication_call_logs")->where("created_at", ">=", now()->subDay())->count() . "\\n";
$latest = DB::table("communication_call_logs")->orderByDesc("id")->limit(5)->get(["id","workspace_id","started_at","created_at","disposition","duration_sec"]);
foreach ($latest as $r) {{
  echo json_encode($r), "\\n";
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
