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
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

echo "local_root=".Storage::disk('local')->path('').PHP_EOL;
echo "local_root_exists=".(is_dir(Storage::disk('local')->path(''))?'yes':'no').PHP_EOL;

foreach ([31,32,33,34,35] as $id) {
  $w = Workflow::find($id);
  if (!$w) continue;
  $fp = (string) $w->file_path;
  $viaStorage = Storage::disk('local')->path($fp);
  $viaApp = storage_path('app/'.$fp);
  $viaPrivate = storage_path('app/private/'.$fp);
  echo "WF{$id} mode={$w->processing_mode} map=".json_encode($w->column_mapping)."\n";
  echo "  file_path={$fp}\n";
  echo "  storage=". (is_file($viaStorage)?'YES':'no') ." {$viaStorage}\n";
  echo "  app=". (is_file($viaApp)?'YES':'no') ." {$viaApp}\n";
  echo "  private=". (is_file($viaPrivate)?'YES':'no') ." {$viaPrivate}\n";
  echo "  exists_disk=". (Storage::disk('local')->exists($fp)?'YES':'no') ."\n";
}

echo "\n=== find csvs ===\n";
echo shell_exec("find ".escapeshellarg(storage_path('app'))." -name '*.csv' -type f 2>/dev/null | head -n 40") ?? '';

echo "\n=== jobs payload file paths ===\n";
foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $cmd = @unserialize($payload['data']['command'] ?? '');
  if (is_object($cmd)) {
    echo "job{$j->id} wf={$cmd->workflowId} path={$cmd->filePath}\n";
  }
}
"""

(ROOT / "deploy/_find_csv.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_find_csv.php", "scripts/_find_csv.php")], app_root=REMOTE_APP)
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_find_csv.php",
        f"ls -la {REMOTE_APP}/storage/app/ 2>/dev/null | head",
        f"ls -la {REMOTE_APP}/storage/app/private/workflows/ 2>/dev/null | head -n 30 || ls -la {REMOTE_APP}/storage/app/workflows/ 2>/dev/null | head -n 30 || echo NO_WORKFLOWS_DIR",
        f"rm -f {REMOTE_APP}/scripts/_find_csv.php",
    ], check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
finally:
    ssh.close()
    p = ROOT / "deploy/_find_csv.php"
    if p.exists():
        p.unlink()
