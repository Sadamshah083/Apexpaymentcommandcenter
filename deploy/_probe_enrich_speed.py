#!/usr/bin/env python3
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
from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Support\Facades\DB;

$w = Workflow::find(18);
$counts = $w ? $w->leads()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n','status') : [];
echo "wf18 status={$w->status} total={$w->total_leads} enriched={$w->enriched_leads} failed={$w->failed_leads} counts=".json_encode($counts).PHP_EOL;
echo "jobs=".DB::table('jobs')->count()." failed_jobs=".DB::table('failed_jobs')->count().PHP_EOL;

$geminiKey = filled(config('gemini.api_key')) || filled(env('GEMINI_API_KEY')) || filled(env('GOOGLE_API_KEY'));
$orKey = filled(config('openrouter.api_key'));
echo "gemini_key=".($geminiKey?'yes':'no')." openrouter_key=".($orKey?'yes':'no').PHP_EOL;
echo "gemini_model=".config('workflow_enrichment.gemini_model')." or_model=".config('openrouter.model').PHP_EOL;
echo "or_fallback=".json_encode(config('openrouter.fallback_models')).PHP_EOL;
echo "workers=".config('queue.workers')." followup=".json_encode(config('workflow_enrichment.follow_up_enabled'))." webq=".config('workflow_enrichment.web_search_queries').PHP_EOL;

$health = app(\App\Services\Workflow\WorkflowProviderStatusService::class)->getGeminiHealth();
echo "gemini_health=".json_encode($health).PHP_EOL;

$status = app(\App\Services\Workflow\WorkflowProviderStatusService::class)->getEnrichmentStatus(true, false);
echo "enrichment_status=".json_encode($status).PHP_EOL;
"""

ssh = connect()
try:
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/apex_enrich_speed.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, "php /tmp/apex_enrich_speed.php", check=False))
    print("--- env keys present (names only) ---")
    print(sudo_run(
        ssh,
        "cd /var/www/apexone && grep -E '^(GEMINI|GOOGLE_API|OPENROUTER|QUEUE_WORKERS|WORKFLOW_)' .env | sed 's/=.*/=***/' ",
        check=False,
    ))
    print("--- recent DONE vs FAIL ---")
    print(sudo_run(
        ssh,
        "journalctl -u apexone-queue.service --since '2 min ago' --no-pager | grep -E 'DONE|FAIL|RUNNING' | tail -40",
        check=False,
    ))
finally:
    ssh.close()
