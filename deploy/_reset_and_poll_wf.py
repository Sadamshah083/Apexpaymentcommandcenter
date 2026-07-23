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

RESET = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

echo 'website_col='.DB::selectOne("SHOW COLUMNS FROM workflow_leads LIKE 'website'")->Type.PHP_EOL;
Cache::forget('illuminate:queue:restart');

foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $name = $payload['displayName'] ?? '?';
  echo "job {$j->id} {$name} attempts={$j->attempts}\n";
  $cmd = @unserialize($payload['data']['command'] ?? '');
  if (is_object($cmd) && isset($cmd->workflowId) && in_array($cmd->workflowId, [31,32,33,34,35], true)) {
    DB::table('jobs')->where('id', $j->id)->delete();
    echo "  deleted\n";
  }
}

foreach ([31,32,33,34,35] as $id) {
  $w = Workflow::find($id);
  if (!$w) { echo "WF{$id} missing\n"; continue; }
  $w->update([
    'status' => 'extracting',
    'error_message' => null,
    'processing_mode' => 'import_and_enrich',
    'ingestion_complete' => false,
  ]);
  ProcessWorkflowJob::dispatch($w->id, $w->file_path);
  echo "WF{$id} offset=".($w->fresh()->ingestion_row_offset??0)." leads=".$w->leads()->count()." status=".$w->fresh()->status."\n";
}
echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
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
  $rows = (int) DB::table('workflow_leads')->where('workflow_id',$id)->count();
  $imported = (int) DB::table('workflow_leads')->where('workflow_id',$id)->where('status','imported')->count();
  $enriched = (int) DB::table('workflow_leads')->where('workflow_id',$id)->whereIn('status',['enriched','completed'])->count();
  $failed = (int) DB::table('workflow_leads')->where('workflow_id',$id)->where('status','failed')->count();
  $extracting = (int) DB::table('workflow_leads')->where('workflow_id',$id)->where('status','extracting')->count();
  echo "WF{$id} status={$w->status} total={$w->total_leads} ingest=".($w->ingestion_complete?'1':'0')." rows={$rows} imported={$imported} extracting={$extracting} enriched={$enriched} failed={$failed} err=".substr(str_replace("\n"," ",(string)$w->error_message),0,100)."\n";
}
echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
"""

(ROOT/"deploy/_reset_wf.php").write_text(RESET, encoding="utf-8")
(ROOT/"deploy/_poll_wf2.php").write_text(POLL, encoding="utf-8")

ssh = connect()
try:
    upload_files(ssh, [
        (ROOT/"deploy/_reset_wf.php", "scripts/_reset_wf.php"),
        (ROOT/"deploy/_poll_wf2.php", "scripts/_poll_wf2.php"),
        (ROOT/"app/Jobs/ProcessWorkflowJob.php", "app/Jobs/ProcessWorkflowJob.php"),
    ], app_root=REMOTE_APP)

    print("=== kill/restart workers ===")
    print(sudo_run(ssh, "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 2; echo killed", check=False))

    print("=== reset ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_reset_wf.php", check=False))

    print("=== start pool ===")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --workers=4 --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'; sleep 2; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep", check=False))

    for i in range(12):
        print(f"--- poll {i} ---")
        print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_poll_wf2.php", check=False))
        time.sleep(8)

    print("=== recent errors ===")
    print(sudo_run(ssh, f"grep -E 'Workflow processing|Data too long' {REMOTE_APP}/storage/logs/laravel.log | tail -n 10", check=False))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_reset_wf.php {REMOTE_APP}/scripts/_poll_wf2.php", check=False)
finally:
    ssh.close()
    for name in ["_reset_wf.php", "_poll_wf2.php"]:
        p = ROOT / "deploy" / name
        if p.exists():
            p.unlink()
