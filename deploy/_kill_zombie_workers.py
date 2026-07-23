#!/usr/bin/env python3
from __future__ import annotations
import os, sys, time
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run

ssh = connect()
try:
    print(sudo_run(ssh, """
pkill -9 -f 'artisan queue:pool' || true
pkill -9 -f 'artisan queue:work' || true
sleep 2
ps aux | grep -E 'queue:pool|queue:work' | grep -v grep || echo NONE
""", check=False))

    print(sudo_run(ssh, f"""
cd {REMOTE_APP}
# Drop leftover enrichment jobs for paused WF36 so they stop regenerating load
sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
use Illuminate\\Support\\Facades\\DB;
$n=0;
foreach (DB::table("jobs")->get() as $j) {{
  $p=json_decode($j->payload,true)?:[];
  $name=$p["displayName"]??"";
  if (str_contains($name,"ProcessPendingUpdates") || str_contains($name,"ProcessLeadJob")) {{
    DB::table("jobs")->where("id",$j->id)->delete(); $n++;
  }}
}}
echo "purged=$n\\n";
Illuminate\\Support\\Facades\\Cache::forget("illuminate:queue:restart");
'
sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'
sleep 2
ps aux | grep -E 'queue:pool|queue:work' | grep -v grep
""", check=False).encode("ascii","replace").decode("ascii"))

    time.sleep(3)
    print(sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach ([38,39] as $id) {{
  $w=Illuminate\\Support\\Facades\\DB::table("workflows")->find($id);
  $ready=Illuminate\\Support\\Facades\\DB::table("workflow_leads")->where("workflow_id",$id)->where("status","imported")->where("import_mode","stored")->whereNull("assigned_user_id")->count();
  echo "WF$id status=$w->status mode=$w->processing_mode total=$w->total_leads ready=$ready\\n";
}}
echo "jobs=".Illuminate\\Support\\Facades\\DB::table("jobs")->count()."\\n";
'""", check=False).encode("ascii","replace").decode("ascii"))
finally:
    ssh.close()
