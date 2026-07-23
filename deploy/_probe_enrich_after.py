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

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "rpm=".config('workflow_enrichment.openrouter_fallback_rpm')." retry=".config('workflow_enrichment.openrouter_retry_delay_seconds')."\n";
echo "jobs=".DB::table('jobs')->count()."\n";
foreach ([18,22,23] as $id) {
  $w=App\Models\Workflow::find($id);
  if(!$w) continue;
  $enriched=App\Models\WorkflowLead::where('workflow_id',$id)->enrichmentSucceeded()->count();
  $pending=App\Models\WorkflowLead::where('workflow_id',$id)->whereNull('researched_at')->count();
  $statusE=App\Models\WorkflowLead::where('workflow_id',$id)->where('status','enriched')->count();
  echo "wf{$id} status={$w->status} col_enriched={$w->enriched_leads} real_enriched={$enriched} status_enriched_only={$statusE} pending={$pending} total={$w->total_leads}\n";
}
"""

ssh=connect()
try:
  sftp=ssh.open_sftp()
  with sftp.file('/tmp/apex_enr3.php','w') as f: f.write(PHP)
  sftp.close()
  print(sudo_run(ssh,'php /tmp/apex_enr3.php',check=False))
  print(sudo_run(ssh,"tail -n 30 /var/www/apexone/storage/logs/laravel.log | tr -cd '\\11\\12\\15\\40-\\176' | tail -n 20",check=False))
finally:
  ssh.close()
