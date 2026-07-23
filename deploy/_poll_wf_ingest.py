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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;

foreach ([31,32,33,34,35] as $id) {
  $w = Workflow::find($id);
  if (!$w) { echo "WF{$id} missing\n"; continue; }
  $rows = DB::table('workflow_leads')->where('workflow_id',$id)->count();
  $imported = DB::table('workflow_leads')->where('workflow_id',$id)->where('status','imported')->count();
  $enriched = DB::table('workflow_leads')->where('workflow_id',$id)->whereIn('status',['enriched','completed'])->count();
  $failed = DB::table('workflow_leads')->where('workflow_id',$id)->where('status','failed')->count();
  echo "WF{$id} status={$w->status} total={$w->total_leads} ingest=".($w->ingestion_complete?'1':'0')." rows={$rows} imported={$imported} enriched={$enriched} failed={$failed} err=".substr((string)$w->error_message,0,100)."\n";
}
echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
echo 'failed_jobs='.DB::table('failed_jobs')->count().PHP_EOL;
$workers = trim(shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l") ?? '0');
echo "workers={$workers}\n";
"""

(ROOT / "deploy/_poll_wf.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_poll_wf.php", "scripts/_poll_wf.php")], app_root=REMOTE_APP)
    for i in range(6):
        out = sudo_run_batch(ssh, [f"cd {REMOTE_APP} && sudo -u www-data php scripts/_poll_wf.php"])
        print(f"--- poll {i} ---")
        print(out.encode("ascii", "replace").decode("ascii"))
        if "rows=0" not in out and "total=0" not in out:
            break
        # if still all zero after workers exist, keep waiting
        time.sleep(15)
    sudo_run_batch(ssh, [f"rm -f {REMOTE_APP}/scripts/_poll_wf.php"])
finally:
    ssh.close()
    p = ROOT / "deploy/_poll_wf.php"
    if p.exists():
        p.unlink()
