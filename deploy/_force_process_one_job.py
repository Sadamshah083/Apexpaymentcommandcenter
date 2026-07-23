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

ssh = connect()
try:
    print("=== kill workers ===")
    print(sudo_run(ssh, "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 2; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep || echo NONE", check=False))

    print("=== clear restart signal ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php artisan tinker --execute=\"Illuminate\\\\Support\\\\Facades\\\\Cache::forget('illuminate:queue:restart'); echo 'cleared';\"", check=False))

    print("=== queue:work --once ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && timeout 90 sudo -u www-data php artisan queue:work database --queue=default --once --tries=1 --timeout=60 -vvv 2>&1; echo EXIT:$?", check=False))

    print("=== status after one ===")
    print(sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$w=App\\Models\\Workflow::find(31);
echo "WF31 total=".$w->total_leads." status=".$w->status." ingest=".($w->ingestion_complete?"1":"0")."\\n";
echo "jobs=".Illuminate\\Support\\Facades\\DB::table("jobs")->count()."\\n";
echo "leads=".Illuminate\\Support\\Facades\\DB::table("workflow_leads")->where("workflow_id",31)->count()."\\n";
'""", check=False))

    print("=== last laravel log lines mentioning Workflow/Queue ===")
    print(sudo_run(ssh, f"grep -E 'Processing workflow|Workflow processing|queue|ProcessWorkflow' {REMOTE_APP}/storage/logs/laravel.log | tail -n 30", check=False))

    print("=== restart pool ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'; sleep 2; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep", check=False))
finally:
    ssh.close()
