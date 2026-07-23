#!/usr/bin/env python3
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

ssh = connect()
try:
    print("=== sync/stream errors ===")
    print(sudo_run(
        ssh,
        "cd /var/www/apexone && tail -2000 storage/logs/laravel.log "
        "| grep -Ei 'WorkspaceSync|sync\\.stream|fingerprintProgress' "
        "| grep -Ei 'error|exception|undefined|fatal' | tail -30",
        check=False,
    ) or "NONE")
    print("=== workflow 18 final ===")
    print(sudo_run(
        ssh,
        "php -r \"require '/var/www/apexone/vendor/autoload.php';"
        "\\$a=require '/var/www/apexone/bootstrap/app.php';"
        "\\$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
        "\\$w=App\\Models\\Workflow::find(18);"
        "echo \\$w->status.' enriched='.\\$w->enriched_leads.' failed='.\\$w->failed_leads.' total='.\\$w->total_leads;\"",
        check=False,
    ))
finally:
    ssh.close()
