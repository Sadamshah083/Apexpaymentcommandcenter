#!/usr/bin/env python3
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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Models\Workflow;

echo "queue_default=".config('queue.default').PHP_EOL;
echo "db_queue=".config('queue.connections.database.queue').PHP_EOL;
echo "db_table=".config('queue.connections.database.table').PHP_EOL;
echo "retry_after=".config('queue.connections.database.retry_after').PHP_EOL;

$j = DB::table('jobs')->orderBy('id')->first();
if ($j) {
  echo "job_id={$j->id} queue={$j->queue} attempts={$j->attempts}\n";
  echo "available_at={$j->available_at} now=".time()."\n";
  echo "reserved_at=".var_export($j->reserved_at, true)."\n";
  $payload = json_decode($j->payload, true);
  echo "displayName=".($payload['displayName']??'?')."\n";
  echo "job=".($payload['job']??'?')."\n";
  $cmd = unserialize($payload['data']['command'] ?? '');
  if (is_object($cmd)) {
    echo "command_class=".get_class($cmd)."\n";
    if (property_exists($cmd, 'workflowId')) echo "workflowId=".$cmd->workflowId."\n";
    if (property_exists($cmd, 'filePath')) echo "filePath=".$cmd->filePath."\n";
  } else {
    echo "UNSERIALIZE_FAILED\n";
  }
}

$w = Workflow::find(31);
echo "WF31 paused=".($w && $w->isPaused()?'yes':'no')." status={$w->status} mode=".($w->processing_mode??'')." file=".($w->file_path??'')."\n";
echo "file_exists=".(is_file(storage_path('app/'.$w->file_path))?'yes':'no')."\n";
"""

(ROOT / "deploy/_inspect_job.php").write_text(PHP, encoding="utf-8")

ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_inspect_job.php", "scripts/_inspect_job.php")], app_root=REMOTE_APP)
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_inspect_job.php",
        # Kill pool so we can run once cleanly
        "pkill -f 'artisan queue:pool' || true; pkill -f 'artisan queue:work' || true; sleep 1",
        # Process exactly one job with verbose output
        f"cd {REMOTE_APP} && sudo -u www-data php artisan queue:work database --once --tries=1 --timeout=120 -vvv 2>&1 | tail -n 80; echo EXIT:$?",
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_inspect_job.php",
        # Restart pool
        f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'",
        "sleep 1; ps aux | grep -E 'queue:pool|queue:work' | grep -v grep",
        f"rm -f {REMOTE_APP}/scripts/_inspect_job.php",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
finally:
    ssh.close()
    p = ROOT / "deploy/_inspect_job.php"
    if p.exists():
        p.unlink()
