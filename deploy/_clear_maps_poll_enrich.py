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

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

// Kill stuck maps scrape jobs
$deletedMaps = 0;
foreach (DB::table('jobs')->orderBy('id')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $name = $payload['displayName'] ?? '';
  if (str_contains($name, 'RunMapsScrapeJob') || str_contains($name, 'ProcessPendingUpdates')) {
    DB::table('jobs')->where('id', $j->id)->delete();
    $deletedMaps++;
  }
}
echo "deleted_noise_jobs={$deletedMaps}\n";

// Clear failed maps jobs
$failedCleared = DB::table('failed_jobs')->where('payload', 'like', '%RunMapsScrapeJob%')->delete();
echo "cleared_failed_maps={$failedCleared}\n";

Cache::forget('illuminate:queue:restart');

$w = DB::table('workflows')->where('id', 36)->first();
if ($w) {
  echo "WF36 status={$w->status} total={$w->total_leads} enriched_col=".($w->enriched_leads??'?')." failed_col=".($w->failed_leads??'?')."\n";
}
foreach ([36,37] as $id) {
  $rows = DB::table('workflow_leads')->where('workflow_id',$id)->count();
  $by = DB::table('workflow_leads')->select('status', DB::raw('count(*) c'))->where('workflow_id',$id)->groupBy('status')->pluck('c','status');
  echo "WF{$id} rows={$rows} by=".json_encode($by)."\n";
}

$names = [];
foreach (DB::table('jobs')->get() as $j) {
  $payload = json_decode($j->payload, true) ?: [];
  $n = $payload['displayName'] ?? '?';
  $names[$n] = ($names[$n] ?? 0) + 1;
}
echo 'job_counts='.json_encode($names).PHP_EOL;
echo 'workers='.trim(shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l") ?? '0').PHP_EOL;

// recent enrichment log
echo "\n=== recent enrichment logs ===\n";
"""

(ROOT/"deploy/_enrich_status.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT/"deploy/_enrich_status.php", "scripts/_enrich_status.php")], app_root=REMOTE_APP)

    # ensure extractors are deployed
    upload_files(ssh, [
        (ROOT/"app/Services/Workflow/WorkflowExtractor.php", "app/Services/Workflow/WorkflowExtractor.php"),
        (ROOT/"app/Services/Workflow/WorkflowProviderStatusService.php", "app/Services/Workflow/WorkflowProviderStatusService.php"),
    ], app_root=REMOTE_APP)

    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_enrich_status.php", check=False).encode("ascii","replace").decode("ascii"))
    print(sudo_run(ssh, f"grep -E 'OpenRouter|Gemini enrichment|sheet enrichment|AI enrichment unavailable|enrichment' {REMOTE_APP}/storage/logs/laravel.log | tail -n 25", check=False).encode("ascii","replace").decode("ascii"))

    for i in range(6):
        time.sleep(15)
        out = sudo_run(ssh, f"""cd {REMOTE_APP} && sudo -u www-data php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$by=Illuminate\\Support\\Facades\\DB::table("workflow_leads")->select("status", Illuminate\\Support\\Facades\\DB::raw("count(*) c"))->where("workflow_id",36)->groupBy("status")->pluck("c","status");
$w=Illuminate\\Support\\Facades\\DB::table("workflows")->find(36);
echo "poll status=".$w->status." total=".$w->total_leads." enriched=".$w->enriched_leads." failed=".$w->failed_leads." by=".json_encode($by)." jobs=".Illuminate\\Support\\Facades\\DB::table("jobs")->count()."\\n";
'""", check=False)
        print(out.encode("ascii","replace").decode("ascii"))

    sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_enrich_status.php", check=False)
finally:
    ssh.close()
    p = ROOT/"deploy/_enrich_status.php"
    if p.exists():
        p.unlink()
