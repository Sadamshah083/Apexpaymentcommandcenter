#!/usr/bin/env python3
"""Probe enrichment provider health and recent workflow zeros on production."""

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

echo 'GEMINI_KEY_SET='.(filled(config('gemini.api_key')) ? 'yes' : 'no').PHP_EOL;
echo 'GEMINI_KEY_PREFIX='.substr((string)config('gemini.api_key'),0,8).PHP_EOL;
echo 'GEMINI_KEY_LEN='.strlen((string)config('gemini.api_key')).PHP_EOL;
echo 'OPENROUTER_KEY_SET='.(filled(config('openrouter.api_key')) ? 'yes' : 'no').PHP_EOL;
echo 'OPENROUTER_KEY_PREFIX='.substr((string)config('openrouter.api_key'),0,12).PHP_EOL;
echo 'PIPELINE_MODEL='.config('workflow_enrichment.gemini_model').PHP_EOL;
echo 'BASE_URL='.config('gemini.base_url').PHP_EOL;

$status = app(WorkflowProviderStatusService::class)->getStatus(true);
echo 'STATUS='.json_encode($status, JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'CACHED_GEMINI_ERROR='.json_encode(Cache::get('workflow.gemini_last_error')).PHP_EOL;

// Live Gemini probe
$key = (string) config('gemini.api_key');
$model = (string) config('workflow_enrichment.gemini_model', 'gemini-2.0-flash');
if ($key !== '') {
  $url = rtrim((string)config('gemini.base_url'),'/').'/models/'.$model.':generateContent?key='.urlencode($key);
  $res = Http::timeout(20)->post($url, [
    'contents' => [['parts' => [['text' => 'Reply with OK']]]],
    'generationConfig' => ['maxOutputTokens' => 8],
  ]);
  echo 'GEMINI_HTTP='.$res->status().PHP_EOL;
  echo 'GEMINI_BODY='.substr($res->body(),0,500).PHP_EOL;
}

// Recent workflows with 0 totals
$wfs = Workflow::query()->latest('id')->limit(8)->get(['id','name','status','total_leads','enriched_count','created_at','campaign_id']);
foreach ($wfs as $w) {
  $leads = DB::table('workflow_leads')->where('workflow_id',$w->id)->count();
  echo "WF id={$w->id} status={$w->status} total={$w->total_leads} enriched={$w->enriched_count} leads_rows={$leads} name={$w->name}\n";
}

echo 'QUEUE_FAILED='.DB::table('failed_jobs')->count().PHP_EOL;
$failed = DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get(['id','queue','exception','failed_at']);
foreach ($failed as $f) {
  echo 'FAILED '.$f->id.' '.$f->failed_at.' '.substr(str_replace("\n",' ',$f->exception),0,220).PHP_EOL;
}
"""


def main() -> int:
    (ROOT / "deploy/_probe_enrich.php").write_text(PHP, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / "deploy/_probe_enrich.php", "scripts/_probe_enrich.php")], app_root=REMOTE_APP)
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_enrich.php",
            f"grep -E '^(GEMINI_|OPENROUTER_|GOOGLE_)' {REMOTE_APP}/.env | sed 's/=.*/=***/'",
            f"rm -f {REMOTE_APP}/scripts/_probe_enrich.php",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        return 0
    finally:
        ssh.close()
        p = ROOT / "deploy/_probe_enrich.php"
        if p.exists():
            p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
