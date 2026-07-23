#!/usr/bin/env python3
from __future__ import annotations
import os, sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")
import deploy._ssh as m
m.HOST="203.215.161.236"; m.USER="ateg"; m.PASSWORD="balitech1"; m.REMOTE_APP="/var/www/apexone"
from deploy._ssh import connect, sudo_run
PHP=r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app=require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$q=App\Models\CommunicationCallLog::query()->where('created_at','>=',now()->startOfDay());
echo "today=".$q->count()."\n";
echo "ready=".(clone $q)->where('recording_status','ready')->whereNotNull('recording_file_id')->count()."\n";
echo "dur_gt0_no_file=".(clone $q)->where('duration_sec','>',0)->where(function($x){$x->whereNull('recording_file_id')->orWhere('recording_file_id','');})->count()."\n";
$svc=app(App\Services\Communications\AgentStatusReportService::class);
$ws=App\Models\Workspace::find(2);
$rows=$svc->statusSummary($ws, now()->startOfDay(), now()->endOfDay());
foreach($rows as $r){ if(in_array(strtolower($r['status']),['initiated','connected','completed'],true)) echo "LEAK ".$r['status']."\n"; }
echo "status_rows=".count($rows)." first=".($rows[0]['status']??'-')."\n";
$hasInit=collect($rows)->contains(fn($r)=>strtolower($r['status'])==='initiated');
$hasConn=collect($rows)->contains(fn($r)=>strtolower($r['status'])==='connected');
echo "has_initiated=".($hasInit?'yes':'no')." has_connected=".($hasConn?'yes':'no')."\n";
"""
ssh=connect()
try:
  sftp=ssh.open_sftp();
  with sftp.file('/tmp/apex_verify_find.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_verify_find.php',check=False))
finally: ssh.close()
