#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, r"""
cd /var/www/apexone
php artisan tinker --execute="
\$rows = App\Models\CommunicationCallLog::query()
  ->whereNotNull('disposition')
  ->selectRaw('disposition, count(*) as c, avg(duration_sec) as avg_dur, sum(case when duration_sec > 0 then 1 else 0 end) as with_dur, sum(case when duration_sec >= 15 then 1 else 0 end) as ge15')
  ->groupBy('disposition')
  ->orderByDesc('c')
  ->limit(12)
  ->get();
echo \$rows->toJson();
"
"""))
finally:
    ssh.close()
