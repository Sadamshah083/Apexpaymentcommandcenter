#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST = "203.215.161.236"; m.USER = "ateg"; m.PASSWORD = "balitech1"; m.REMOTE_APP = "/var/www/apexone"
from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
DB::table('jobs')->delete();
Cache::forget('illuminate:queue:restart');
echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
"""
(ROOT/"deploy/_clear_jobs.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT/"deploy/_clear_jobs.php", "scripts/_clear_jobs.php")], app_root=REMOTE_APP)
    print(sudo_run(ssh, "pkill -9 -f 'artisan queue:' || true; sleep 2; echo killed", check=False))
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_clear_jobs.php", check=False))
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'; sleep 2; ps aux | grep 'queue:' | grep -v grep", check=False))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_clear_jobs.php", check=False)
finally:
    ssh.close()
    p = ROOT/"deploy/_clear_jobs.php"
    if p.exists(): p.unlink()
