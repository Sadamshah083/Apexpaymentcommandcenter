#!/usr/bin/env python3
"""Deploy ingest-priority queues so upload-only never waits behind enrichment."""
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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Jobs/ProcessWorkflowJob.php",
    "app/Jobs/ProcessLeadJob.php",
    "app/Console/Commands/QueuePoolCommand.php",
    "app/Services/Workflow/WorkflowService.php",
    "resources/views/workflows/show.blade.php",
]

REPAIR = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

Cache::forget('illuminate:queue:restart');

$movedIngest = 0;
$deletedEnrich = 0;
$deletedNoise = 0;

foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
    $payload = json_decode($j->payload, true) ?: [];
    $name = $payload['displayName'] ?? '';

    if (str_contains($name, 'ProcessPendingUpdates')) {
        DB::table('jobs')->where('id', $j->id)->delete();
        $deletedNoise++;
        continue;
    }

    if (str_contains($name, 'ProcessWorkflowJob')) {
        if ($j->queue !== 'ingest') {
            DB::table('jobs')->where('id', $j->id)->update(['queue' => 'ingest', 'reserved_at' => null, 'attempts' => 0]);
            $movedIngest++;
        }
        continue;
    }

    if (str_contains($name, 'ProcessLeadJob')) {
        $cmd = @unserialize($payload['data']['command'] ?? '');
        $leadId = is_object($cmd) && isset($cmd->leadId) ? (int) $cmd->leadId : 0;
        $wfId = 0;
        if ($leadId > 0) {
            $wfId = (int) (DB::table('workflow_leads')->where('id', $leadId)->value('workflow_id') ?? 0);
        }
        $wf = $wfId ? Workflow::find($wfId) : null;
        // Drop enrichment jobs for paused/import_only workflows so uploads are not blocked.
        if (! $wf || $wf->isPaused() || $wf->isImportOnly() || $wf->status === 'completed') {
            DB::table('jobs')->where('id', $j->id)->delete();
            $deletedEnrich++;
            continue;
        }
        if ($j->queue !== 'enrichment') {
            DB::table('jobs')->where('id', $j->id)->update(['queue' => 'enrichment', 'reserved_at' => null]);
        }
    }
}

echo "moved_ingest={$movedIngest} deleted_enrich={$deletedEnrich} deleted_noise={$deletedNoise}\n";

foreach ([38, 39] as $id) {
    $w = Workflow::find($id);
    if (! $w || ! $w->file_path) {
        echo "WF{$id} skip\n";
        continue;
    }
    if ($w->isImportOnly() && in_array($w->status, ['pending', 'paused', 'failed', 'extracting'], true) && ! $w->ingestion_complete) {
        $w->update([
            'status' => 'pending',
            'error_message' => null,
            'processing_mode' => 'import_only',
        ]);
        // Avoid duplicate ingest jobs
        foreach (DB::table('jobs')->get() as $j) {
            $payload = json_decode($j->payload, true) ?: [];
            $cmd = @unserialize($payload['data']['command'] ?? '');
            if (is_object($cmd) && $cmd instanceof ProcessWorkflowJob && (int) $cmd->workflowId === (int) $id) {
                DB::table('jobs')->where('id', $j->id)->delete();
            }
        }
        ProcessWorkflowJob::dispatch($w->id, $w->file_path);
        echo "WF{$id} requeued_ingest mode={$w->processing_mode}\n";
    } else {
        echo "WF{$id} status={$w->status} mode={$w->processing_mode} total={$w->total_leads} ingest=".($w->ingestion_complete?'1':'0')."\n";
    }
}

$names = [];
foreach (DB::table('jobs')->get() as $j) {
    $payload = json_decode($j->payload, true) ?: [];
    $n = ($payload['displayName'] ?? '?').'@'.$j->queue;
    $names[$n] = ($names[$n] ?? 0) + 1;
}
echo 'jobs='.json_encode($names).PHP_EOL;
"""

ssh = connect()
try:
    pairs = [(ROOT / rel, rel) for rel in FILES]
    print("=== upload ===")
    upload_files(ssh, pairs, app_root=REMOTE_APP)
    (ROOT / "deploy/_repair_queues.php").write_text(REPAIR, encoding="utf-8")
    upload_files(ssh, [(ROOT / "deploy/_repair_queues.php", "scripts/_repair_queues.php")], app_root=REMOTE_APP)

    print("=== clear + repair + restart pool ===")
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear && sudo -u www-data php artisan config:clear",
        "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 2",
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_repair_queues.php",
        f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'",
        "sleep 3; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep | head -n 12",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))

    import time
    for i in range(6):
        time.sleep(8)
        poll = sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
foreach ([36,38,39] as $id) {{
  $w=Illuminate\\Support\\Facades\\DB::table("workflows")->find($id);
  if(!$w) {{ echo "WF$id missing\\n"; continue; }}
  $rows=Illuminate\\Support\\Facades\\DB::table("workflow_leads")->where("workflow_id",$id)->count();
  echo "WF$id status=$w->status mode=$w->processing_mode total=$w->total_leads ingest=".($w->ingestion_complete?1:0)." rows=$rows\\n";
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
        print(poll.encode("ascii", "replace").decode("ascii"))

    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_repair_queues.php", check=False)
finally:
    ssh.close()
    p = ROOT / "deploy/_repair_queues.php"
    if p.exists():
        p.unlink()
