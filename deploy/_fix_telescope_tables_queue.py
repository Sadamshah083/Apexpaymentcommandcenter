#!/usr/bin/env python3
"""Migrate Telescope tables, restart queue, verify WF ingest."""
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

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run_batch, upload_files

POLL = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo 'telescope_entries='.(Schema::hasTable('telescope_entries')?'yes':'NO').PHP_EOL;
echo 'TELESCOPE_ENABLED='.var_export((bool) env('TELESCOPE_ENABLED'), true).PHP_EOL;
foreach ([31,32,33,34,35] as $id) {
  $w = Workflow::find($id);
  if (!$w) { echo "WF{$id} missing\n"; continue; }
  $rows = DB::table('workflow_leads')->where('workflow_id',$id)->count();
  echo "WF{$id} status={$w->status} total={$w->total_leads} ingest=".($w->ingestion_complete?'1':'0')." rows={$rows}\n";
}
echo 'jobs='.DB::table('jobs')->count().PHP_EOL;
$j = DB::table('jobs')->orderBy('id')->first();
if ($j) {
  $p = json_decode($j->payload, true) ?: [];
  echo "oldest_job id={$j->id} attempts={$j->attempts} reserved=".($j->reserved_at?:'-')." name=".($p['displayName']??'?').PHP_EOL;
}
echo 'workers='.trim(shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l") ?? '0').PHP_EOL;
"""

(ROOT / "deploy/_poll_after_telescope.php").write_text(POLL, encoding="utf-8")

ssh = connect()
try:
    print("=== migrate telescope + restart queue ===")
    set_env_vars(ssh, {
        "TELESCOPE_ENABLED": "true",
    })
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan migrate --force --no-interaction 2>&1 | tail -n 40",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan queue:restart || true",
        # kill hung pool/workers and respawn cleanly
        "pkill -f 'artisan queue:pool' || true",
        "pkill -f 'artisan queue:work' || true",
        "sleep 2",
        f"cd {REMOTE_APP} && sudo -u www-data bash -c 'nohup php artisan queue:pool --tries=3 --timeout=0 >> storage/logs/queue-pool.log 2>&1 &'",
        "sleep 2",
        "ps aux | grep -E 'queue:pool|queue:work' | grep -v grep | head -n 20",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))

    upload_files(ssh, [(ROOT / "deploy/_poll_after_telescope.php", "scripts/_poll_after_telescope.php")], app_root=REMOTE_APP)

    for i in range(8):
        poll = sudo_run_batch(ssh, [f"cd {REMOTE_APP} && sudo -u www-data php scripts/_poll_after_telescope.php"])
        print(f"--- poll {i} ---")
        print(poll.encode("ascii", "replace").decode("ascii"))
        if "rows=0" not in poll and "total=0" not in poll:
            break
        if "telescope_entries=NO" in poll:
            print("WARN: telescope table still missing")
        time.sleep(12)

    # show recent queue log
    log = sudo_run_batch(ssh, [
        f"tail -n 40 {REMOTE_APP}/storage/logs/queue-pool.log 2>/dev/null || true",
        f"rm -f {REMOTE_APP}/scripts/_poll_after_telescope.php",
    ], check=False)
    print("=== queue-pool.log ===")
    print(log.encode("ascii", "replace").decode("ascii")[-3000:])
finally:
    ssh.close()
    p = ROOT / "deploy/_poll_after_telescope.php"
    if p.exists():
        p.unlink()
