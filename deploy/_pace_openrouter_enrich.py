#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

ssh = connect()
try:
    # Pace enrichment to OpenRouter free-tier; keep Telescope on but drop job-watcher noise via env if supported
    set_env_vars(ssh, {
        "WORKFLOW_OPENROUTER_FALLBACK_RPM": "10",
        "WORKFLOW_OPENROUTER_RETRY_DELAY": "8",
        "TELESCOPE_ENABLED": "true",
    })

    upload_files(ssh, [
        (ROOT / "config/workflow_enrichment.php", "config/workflow_enrichment.php"),
    ], app_root=REMOTE_APP)

    print(sudo_run(ssh, f"""
cd {REMOTE_APP}
sudo -u www-data php artisan config:clear
# purge telescope update jobs clogging the queue
sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$n=0;
foreach (Illuminate\\Support\\Facades\\DB::table("jobs")->get() as $j) {{
  $p=json_decode($j->payload,true)?:[];
  if (str_contains($p["displayName"]??"", "ProcessPendingUpdates") || str_contains($p["displayName"]??"", "RunMapsScrapeJob")) {{
    Illuminate\\Support\\Facades\\DB::table("jobs")->where("id",$j->id)->delete();
    $n++;
  }}
}}
echo "purged=$n\\n";
Illuminate\\Support\\Facades\\Cache::forget("illuminate:queue:restart");
'
pkill -f 'artisan queue:pool' || true
pkill -f 'artisan queue:work' || true
sleep 2
sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=2 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'
sleep 2
ps aux | grep -E 'queue:pool|queue:work' | grep -v grep
""", check=False).encode("ascii","replace").decode("ascii"))

    for i in range(8):
        time.sleep(20)
        out = sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$by=Illuminate\\Support\\Facades\\DB::table("workflow_leads")->select("status", Illuminate\\Support\\Facades\\DB::raw("count(*) c"))->where("workflow_id",36)->groupBy("status")->pluck("c","status");
$w=Illuminate\\Support\\Facades\\DB::table("workflows")->find(36);
$jobs=Illuminate\\Support\\Facades\\DB::table("jobs")->count();
echo "poll enriched=".$w->enriched_leads." failed=".$w->failed_leads." by=".json_encode($by)." jobs=$jobs\\n";
'""", check=False)
        print(out.encode("ascii","replace").decode("ascii"))
finally:
    ssh.close()
