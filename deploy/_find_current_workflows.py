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

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "=== recent workflows ===\n";
foreach (DB::table('workflows')->orderByDesc('id')->limit(15)->get() as $w) {
  echo "id={$w->id} status={$w->status} mode=".($w->processing_mode??'')." total=".($w->total_leads??0)." ingest=".($w->ingestion_complete?'1':'0')." campaign=".($w->campaign_id??'-')." file=".basename((string)$w->file_path)." err=".substr((string)($w->error_message??''),0,60)."\n";
}

echo "\n=== workflow_leads by workflow (top) ===\n";
foreach (DB::table('workflow_leads')->select('workflow_id', DB::raw('count(*) as c'))->groupBy('workflow_id')->orderByDesc('c')->limit(15)->get() as $r) {
  echo "wf={$r->workflow_id} leads={$r->c}\n";
}

echo "\n=== orphan leads for 31-35 ===\n";
echo 'orphan_31_35='.DB::table('workflow_leads')->whereIn('workflow_id',[31,32,33,34,35])->count().PHP_EOL;

echo "\n=== jobs summary ===\n";
$names = [];
foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $n = $payload['displayName'] ?? '?';
  $names[$n] = ($names[$n] ?? 0) + 1;
  if (($names[$n] ?? 0) <= 2 || str_contains($n, 'Maps') || str_contains($n, 'Workflow')) {
    $cmd = @unserialize($payload['data']['command'] ?? '');
    $extra = '';
    if (is_object($cmd)) {
      if (isset($cmd->workflowId)) $extra .= " wf={$cmd->workflowId}";
      if (isset($cmd->leadId)) $extra .= " lead={$cmd->leadId}";
      if (isset($cmd->jobId)) $extra .= " mapsJob={$cmd->jobId}";
    }
    echo "id={$j->id} attempts={$j->attempts} reserved=".($j->reserved_at?:'-')." {$n}{$extra}\n";
  }
}
echo "counts=".json_encode($names)."\n";

echo "\n=== campaigns ===\n";
foreach (DB::table('campaigns')->orderByDesc('id')->limit(8)->get(['id','name','workspace_id']) as $c) {
  echo "id={$c->id} ws={$c->workspace_id} name={$c->name}\n";
}
"""

(ROOT/"deploy/_find_wfs.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT/"deploy/_find_wfs.php", "scripts/_find_wfs.php")], app_root=REMOTE_APP)
    out = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_find_wfs.php", check=False)
    print(out.encode("ascii", "replace").decode("ascii"))
    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_find_wfs.php", check=False)
finally:
    ssh.close()
    p = ROOT/"deploy/_find_wfs.php"
    if p.exists():
        p.unlink()
