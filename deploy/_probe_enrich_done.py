#!/usr/bin/env python3
from __future__ import annotations
import os, sys
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

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DB::table('jobs')->delete();
foreach ([18, 22, 23] as $id) {
  $w = App\Models\Workflow::find($id);
  $ready = App\Models\WorkflowLead::where('workflow_id', $id)->readyToAssign()->count();
  $enriched = App\Models\WorkflowLead::where('workflow_id', $id)->enrichmentSucceeded()->count();
  $assigned = App\Models\WorkflowLead::where('workflow_id', $id)->whereNotNull('assigned_user_id')->count();
  echo "wf{$id} status={$w->status} enriched={$enriched}/{$w->total_leads} assigned={$assigned} ready={$ready}\n";
}
echo 'jobs=' . DB::table('jobs')->count() . "\n";
echo 'sheet_import=' . App\Models\WorkflowLead::whereIn('workflow_id',[22,23])->where('model_used','sheet-import')->count() . "\n";
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_done.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_done.php", check=False))
finally:
    ssh.close()
