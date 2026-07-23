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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

REPAIR = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

Cache::forget('illuminate:queue:restart');
$movedIngest=0; $deletedEnrich=0; $deletedNoise=0; $movedEnrich=0;

foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $name = $payload['displayName'] ?? '';
  if (str_contains($name, 'ProcessPendingUpdates')) {
    DB::table('jobs')->where('id', $j->id)->delete();
    $deletedNoise++;
    continue;
  }
  if (str_contains($name, 'ProcessWorkflowJob')) {
    DB::table('jobs')->where('id', $j->id)->update(['queue'=>'ingest','reserved_at'=>null,'attempts'=>0]);
    $movedIngest++;
    continue;
  }
  if (str_contains($name, 'ProcessLeadJob')) {
    $cmd = @unserialize($payload['data']['command'] ?? '');
    $leadId = is_object($cmd) && isset($cmd->leadId) ? (int)$cmd->leadId : 0;
    $wfId = $leadId ? (int)(DB::table('workflow_leads')->where('id',$leadId)->value('workflow_id') ?? 0) : 0;
    $wf = $wfId ? Workflow::find($wfId) : null;
    if (!$wf || $wf->isPaused() || $wf->isImportOnly() || in_array($wf->status, ['completed','failed','mapping'], true)) {
      DB::table('jobs')->where('id', $j->id)->delete();
      $deletedEnrich++;
      continue;
    }
    DB::table('jobs')->where('id', $j->id)->update(['queue'=>'enrichment','reserved_at'=>null]);
    $movedEnrich++;
  }
}
echo "moved_ingest=$movedIngest moved_enrich=$movedEnrich deleted_enrich=$deletedEnrich deleted_noise=$deletedNoise\n";

foreach (Workflow::query()->whereIn('id',[38,39])->get() as $w) {
  if (!$w->file_path) { echo "WF{$w->id} nofile\n"; continue; }
  // clear existing ingest jobs for this wf
  foreach (DB::table('jobs')->where('queue','ingest')->get() as $j) {
    $payload = json_decode($j->payload, true) ?: [];
    $cmd = @unserialize($payload['data']['command'] ?? '');
    if (is_object($cmd) && $cmd instanceof ProcessWorkflowJob && (int)$cmd->workflowId === (int)$w->id) {
      DB::table('jobs')->where('id',$j->id)->delete();
    }
  }
  $w->update(['status'=>'pending','error_message'=>null,'processing_mode'=>'import_only','ingestion_complete'=>false]);
  ProcessWorkflowJob::dispatch($w->id, $w->file_path);
  echo "WF{$w->id} dispatched ingest\n";
}

$c=[];
foreach (DB::table('jobs')->get() as $j) {
  $p=json_decode($j->payload,true)?:[];
  $n=($p['displayName']??'?').'@'.$j->queue;
  $c[$n]=($c[$n]??0)+1;
}
echo 'jobs='.json_encode($c)."\n";
echo 'workers_cmd_sample done\n';
"""

ssh = connect()
try:
    # ensure latest job files
    upload_files(ssh, [
        (ROOT/"app/Jobs/ProcessWorkflowJob.php", "app/Jobs/ProcessWorkflowJob.php"),
        (ROOT/"app/Jobs/ProcessLeadJob.php", "app/Jobs/ProcessLeadJob.php"),
        (ROOT/"app/Console/Commands/QueuePoolCommand.php", "app/Console/Commands/QueuePoolCommand.php"),
        (ROOT/"app/Services/Workflow/WorkflowService.php", "app/Services/Workflow/WorkflowService.php"),
        (ROOT/"resources/views/workflows/show.blade.php", "resources/views/workflows/show.blade.php"),
    ], app_root=REMOTE_APP)

    (ROOT/"deploy/_repair_queues.php").write_text(REPAIR, encoding="utf-8")
    upload_files(ssh, [(ROOT/"deploy/_repair_queues.php", "scripts/_repair_queues.php")], app_root=REMOTE_APP)

    print("=== kill workers ===")
    print(sudo_run(ssh, "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 2; echo KILLED", check=False))

    print("=== repair ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_repair_queues.php", check=False).encode("ascii","replace").decode("ascii"))

    print("=== start pool ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &' ; sleep 2; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep", check=False))

    for i in range(8):
        time.sleep(10)
        out = sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach ([38,39] as $id) {{
  $w=Illuminate\\Support\\Facades\\DB::table("workflows")->find($id);
  if(!$w){{echo "WF$id missing\\n";continue;}}
  $rows=Illuminate\\Support\\Facades\\DB::table("workflow_leads")->where("workflow_id",$id)->count();
  echo "WF$id status=$w->status mode=$w->processing_mode total=$w->total_leads ingest=".($w->ingestion_complete?1:0)." rows=$rows err=".substr(str_replace("\\n"," ",(string)$w->error_message),0,80)."\\n";
}}
$c=[];
foreach (Illuminate\\Support\\Facades\\DB::table("jobs")->get() as $j) {{
  $p=json_decode($j->payload,true)?:[];
  $n=($p["displayName"]??"?")."@".$j->queue;
  $c[$n]=($c[$n]??0)+1;
}}
echo "jobs=".json_encode($c)."\\n";
'""", check=False)
        print(f"--- poll {i} ---")
        print(out.encode("ascii","replace").decode("ascii"))
        if "ingest=1" in out and "rows=0" not in out:
            break

    print("=== worker cmdline ===")
    print(sudo_run(ssh, "ps aux | grep 'queue:work' | grep -v grep | head -n 5", check=False))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_repair_queues.php", check=False)
finally:
    ssh.close()
    p = ROOT/"deploy/_repair_queues.php"
    if p.exists(): p.unlink()
