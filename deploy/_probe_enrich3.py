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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

$key = (string) config('gemini.api_key');
$base = rtrim((string)config('gemini.base_url'),'/');

foreach (['gemini-2.5-flash','gemini-2.0-flash','gemini-2.5-flash-lite','gemini-flash-latest'] as $model) {
  $url = $base.'/models/'.$model.':generateContent';
  $res = Http::timeout(20)->withHeaders(['x-goog-api-key'=>$key,'Content-Type'=>'application/json'])
    ->post($url, [
      'contents'=>[['parts'=>[['text'=>'Say OK']]]],
      'generationConfig'=>['maxOutputTokens'=>5,'temperature'=>0],
    ]);
  echo "MODEL {$model} => ".$res->status().' '.substr(($res->json('error.message') ?? $res->body()),0,120).PHP_EOL;
}

// Query param style key
$url = $base.'/models/gemini-2.0-flash:generateContent?key='.urlencode($key);
$res = Http::timeout(20)->post($url, [
  'contents'=>[['parts'=>[['text'=>'Say OK']]]],
  'generationConfig'=>['maxOutputTokens'=>5],
]);
echo 'QUERY_KEY_STYLE => '.$res->status().' '.substr(($res->json('error.message') ?? ''),0,120).PHP_EOL;

$failed = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get();
foreach ($failed as $f) {
  echo "FAILED id={$f->id} at={$f->failed_at}\n";
  echo substr(str_replace("\n"," | ",$f->exception),0,500).PHP_EOL.PHP_EOL;
}

$wf = Workflow::find(35);
if ($wf) {
  echo "WF35 status={$wf->status} path={$wf->file_path} total={$wf->total_leads}\n";
  $abs = storage_path('app/'.$wf->file_path);
  if (!is_file($abs)) $abs = storage_path('app/private/'.$wf->file_path);
  echo 'FILE_EXISTS='.(is_file($abs)?'yes':'no').' path='.$abs.PHP_EOL;
  if (is_file($abs)) {
    echo 'SIZE='.filesize($abs).PHP_EOL;
    echo 'HEAD='.substr(file_get_contents($abs),0,400).PHP_EOL;
  }
}

echo 'PENDING_JOBS='.DB::table('jobs')->count().PHP_EOL;
$jobs = DB::table('jobs')->orderBy('id')->limit(10)->get(['id','queue','payload','attempts','created_at']);
foreach ($jobs as $j) {
  $payload = json_decode($j->payload, true);
  $display = $payload['displayName'] ?? '?';
  echo "JOB {$j->id} q={$j->queue} attempts={$j->attempts} {$display}\n";
}
"""

(ROOT / "deploy/_probe3.php").write_text(PHP, encoding="utf-8")
ssh = connect()
try:
    upload_files(ssh, [(ROOT / "deploy/_probe3.php", "scripts/_probe3.php")], app_root=REMOTE_APP)
    out = sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe3.php",
        f"tail -n 80 {REMOTE_APP}/storage/logs/laravel.log | sed -n '/ProcessWorkflow\\|enrich\\|Gemini\\|denied\\|Workflow/p' | tail -n 40",
        f"rm -f {REMOTE_APP}/scripts/_probe3.php",
    ])
    print(out.encode("ascii", "replace").decode("ascii"))
finally:
    ssh.close()
    p = ROOT / "deploy/_probe3.php"
    if p.exists():
        p.unlink()
