#!/usr/bin/env python3
"""Deploy website column fix + requeue failed workflow ingest."""
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

RESET = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

Cache::forget('illuminate:queue:restart');

// Drop stale ProcessWorkflowJob rows for these workflows
DB::table('jobs')->orderBy('id')->get()->each(function ($j) {
    $payload = json_decode($j->payload, true) ?: [];
    $cmd = @unserialize($payload['data']['command'] ?? '');
    if (is_object($cmd) && $cmd instanceof \App\Jobs\ProcessWorkflowJob && in_array($cmd->workflowId, [31,32,33,34,35], true)) {
        DB::table('jobs')->where('id', $j->id)->delete();
        echo "deleted_job={$j->id} wf={$cmd->workflowId}\n";
    }
});

foreach ([31,32,33,34,35] as $id) {
    $w = Workflow::find($id);
    if (!$w || !$w->file_path) {
        echo "WF{$id} skip\n";
        continue;
    }
    $w->update([
        'status' => 'extracting',
        'error_message' => null,
        'processing_mode' => 'import_and_enrich',
        'ingestion_complete' => false,
        // keep offset/leads so ingest resumes after the long-URL failure
    ]);
    ProcessWorkflowJob::dispatch($w->id, $w->file_path);
    echo "WF{$id} requeued offset=".($w->ingestion_row_offset ?? 0)." leads=".$w->leads()->count()."\n";
}

echo 'jobs_now='.DB::table('jobs')->count().PHP_EOL;
"""

POLL = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
foreach ([31,32,33,34,35] as $id) {
  $w = Workflow::find($id);
  if (!$w) continue;
  $rows = DB::table('workflow_leads')->where('workflow_id',$id)->count();
  $imported = DB::table('workflow_leads')->where('workflow_id',$id)->where('status','imported')->count();
  $enriched = DB::table('workflow_leads')->where('workflow_id',$id)->whereIn('status',['enriched','completed'])->count();
  $failed = DB::table('workflow_leads')->where('workflow_id',$id)->where('status','failed')->count();
  $extracting = DB::table('workflow_leads')->where('workflow_id',$id)->where('status','extracting')->count();
  echo "WF{$id} status={$w->status} mode={$w->processing_mode} total={$w->total_leads} ingest=".($w->ingestion_complete?'1':'0')." rows={$rows} imported={$imported} extracting={$extracting} enriched={$enriched} failed={$failed} err=".substr((string)$w->error_message,0,80)."\n";
}
echo 'jobs='.DB::table('jobs')->count().' workers='.trim(shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l") ?? '0').PHP_EOL;
"""

(ROOT / "deploy/_reset_wf.php").write_text(RESET, encoding="utf-8")
(ROOT / "deploy/_poll_wf2.php").write_text(POLL, encoding="utf-8")

ssh = connect()
try:
    print("=== upload + migrate ===")
    upload_files(ssh, [
        (ROOT / "app/Jobs/ProcessWorkflowJob.php", "app/Jobs/ProcessWorkflowJob.php"),
        (ROOT / "database/migrations/2026_07_20_170000_widen_workflow_leads_website_column.php", "database/migrations/2026_07_20_170000_widen_workflow_leads_website_column.php"),
        (ROOT / "deploy/_reset_wf.php", "scripts/_reset_wf.php"),
        (ROOT / "deploy/_poll_wf2.php", "scripts/_poll_wf2.php"),
    ], app_root=REMOTE_APP)

    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction 2>&1 | tail -n 20",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan optimize:clear 2>&1 | tail -n 10",
        # ensure single clean pool
        "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 2",
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_reset_wf.php",
        f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'",
        "sleep 2; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep | head -n 10",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))

    for i in range(10):
        poll = sudo_run_batch(ssh, [f"cd {REMOTE_APP} && sudo -u www-data php scripts/_poll_wf2.php"], check=False)
        print(f"--- poll {i} ---")
        print(poll.encode("ascii", "replace").decode("ascii"))
        # stop early once all ingested
        if poll.count("ingest=1") >= 5:
            break
        if "Data too long" in poll or "status=failed" in poll and "ingest=0" in poll:
            # keep polling a bit in case partial
            pass
        time.sleep(10)

    sudo_run_batch(ssh, [
        f"rm -f {REMOTE_APP}/scripts/_reset_wf.php {REMOTE_APP}/scripts/_poll_wf2.php",
        f"grep -E 'Workflow processing|Processing workflow|Data too long' {REMOTE_APP}/storage/logs/laravel.log | tail -n 15",
    ], check=False)
finally:
    ssh.close()
    for name in ["_reset_wf.php", "_poll_wf2.php"]:
        p = ROOT / "deploy" / name
        if p.exists():
            p.unlink()
