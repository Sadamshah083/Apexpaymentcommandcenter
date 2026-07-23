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
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$svc = app(WorkflowProviderStatusService::class);
$status = $svc->getEnrichmentStatus(true, true);
echo 'ENRICH_STATUS='.json_encode($status, JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'CACHED_ERR='.json_encode(Cache::get('workflow.gemini_last_error')).PHP_EOL;

$key = (string) config('gemini.api_key');
$model = (string) config('workflow_enrichment.gemini_model', 'gemini-2.5-flash');
$url = rtrim((string)config('gemini.base_url'),'/').'/models/'.$model.':generateContent';
$res = Http::timeout(20)->withHeaders([
  'x-goog-api-key' => $key,
  'Content-Type' => 'application/json',
])->post($url, [
  'contents' => [['parts' => [['text' => 'Reply with OK']]]],
  'generationConfig' => ['maxOutputTokens' => 8, 'temperature' => 0],
]);
echo 'GEMINI_HTTP='.$res->status().PHP_EOL;
echo 'GEMINI_BODY='.substr($res->body(),0,600).PHP_EOL;

// Also try listModels
$list = Http::timeout(15)->withHeaders(['x-goog-api-key'=>$key])->get(rtrim((string)config('gemini.base_url'),'/').'/models');
echo 'LIST_HTTP='.$list->status().PHP_EOL;
echo 'LIST_BODY='.substr($list->body(),0,400).PHP_EOL;

$or = Http::timeout(15)->withHeaders(['Authorization'=>'Bearer '.config('openrouter.api_key')])->get('https://openrouter.ai/api/v1/auth/key');
echo 'OR_HTTP='.$or->status().PHP_EOL;
echo 'OR_BODY='.substr($or->body(),0,400).PHP_EOL;

$wfs = Workflow::query()->latest('id')->limit(8)->get();
foreach ($wfs as $w) {
  $leads = DB::table('workflow_leads')->where('workflow_id',$w->id)->count();
  $path = $w->file_path ?? $w->original_filename ?? null;
  echo "WF id={$w->id} status={$w->status} total={$w->total_leads} enriched={$w->enriched_count} rows={$leads} file=".($w->stored_filename ?? $w->file_path ?? '-')." err=".substr((string)($w->error_message ?? ''),0,120)."\n";
}

echo 'JOBS_PENDING='.DB::table('jobs')->count().PHP_EOL;
echo 'FAILED='.DB::table('failed_jobs')->count().PHP_EOL;
"""

(ROOT / "deploy/_probe2.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_probe2.php", "scripts/_probe2.php")], app_root=REMOTE_APP)
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe2.php",
        f"rm -f {REMOTE_APP}/scripts/_probe2.php",
    ])
    print(out.encode("ascii", "replace").decode("ascii"))
finally:
    ssh.close()
    p = ROOT / "deploy/_probe2.php"
    if p.exists():
        p.unlink()
