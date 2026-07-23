#!/usr/bin/env python3
"""Pace enrichment for depleted Gemini + OpenRouter free quota."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run, sudo_run_batch, upload_files

FILES = [
    "config/openrouter.php",
    "config/workflow_enrichment.php",
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Services/BusinessResearch/OpenRouterClient.php",
    "app/Jobs/ProcessLeadJob.php",
]

REQUEUE = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessLeadJob;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Support\Facades\DB;

$workflow = Workflow::find(18);
if (! $workflow) {
    echo "missing workflow 18\n";
    exit;
}

// Drop thrashing queue jobs and rebuild a clean paced queue for remaining leads.
DB::table('jobs')->delete();

$pending = WorkflowLead::query()
    ->where('workflow_id', $workflow->id)
    ->whereIn('status', ['imported', 'extracting', 'failed'])
    ->orderBy('row_number')
    ->pluck('id');

WorkflowLead::whereIn('id', $pending)->update([
    'status' => 'imported',
    'error_message' => null,
]);

$failed = WorkflowLead::where('workflow_id', $workflow->id)->where('status', 'failed')->count();
$enriched = WorkflowLead::where('workflow_id', $workflow->id)->where('status', 'enriched')->count();

$workflow->update([
    'status' => 'extracting',
    'enriched_leads' => $enriched,
    'failed_leads' => $failed,
]);

foreach ($pending as $i => $leadId) {
    // Stagger so OpenRouter free capacity is not instantly exhausted.
    ProcessLeadJob::dispatch((int) $leadId, $workflow->custom_prompt)
        ->delay(now()->addSeconds((int) floor($i / 1) * 12));
}

echo "requeued={$pending->count()} enriched={$enriched} failed={$failed} model=".config('openrouter.model')." workers=".config('queue.workers').PHP_EOL;
"""


def main() -> None:
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / rel, rel) for rel in FILES])
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/apex_pace_enrich.php", "w") as f:
            f.write(REQUEUE)
        sftp.close()

        print(sudo_run_batch(ssh, [
            # Prefer working free router + single worker while Gemini is depleted.
            f"cd {REMOTE_APP} && sed -i 's/^OPENROUTER_MODEL=.*/OPENROUTER_MODEL=openrouter\\/free/' .env",
            f"cd {REMOTE_APP} && grep -q '^OPENROUTER_MODEL=' .env || echo 'OPENROUTER_MODEL=openrouter/free' >> .env",
            f"cd {REMOTE_APP} && sed -i 's/^OPENROUTER_FALLBACK_MODELS=.*/OPENROUTER_FALLBACK_MODELS=openai\\/gpt-oss-20b:free,meta-llama\\/llama-3.3-70b-instruct:free/' .env",
            f"cd {REMOTE_APP} && sed -i 's/^QUEUE_WORKERS=.*/QUEUE_WORKERS=1/' .env",
            f"cd {REMOTE_APP} && grep -q '^QUEUE_WORKERS=' .env || echo 'QUEUE_WORKERS=1' >> .env",
            f"cd {REMOTE_APP} && sed -i 's/^WORKFLOW_OPENROUTER_FALLBACK_RPM=.*/WORKFLOW_OPENROUTER_FALLBACK_RPM=4/' .env || true",
            f"cd {REMOTE_APP} && grep -q '^WORKFLOW_OPENROUTER_FALLBACK_RPM=' .env || echo 'WORKFLOW_OPENROUTER_FALLBACK_RPM=4' >> .env",
            f"cd {REMOTE_APP} && php artisan config:clear",
            f"cd {REMOTE_APP} && php artisan cache:clear",
            "systemctl restart apexone-queue.service",
        ], check=False))

        time.sleep(2)
        print("--- requeue paced ---")
        print(sudo_run(ssh, "php /tmp/apex_pace_enrich.php", check=False))
        print("--- workers ---")
        print(sudo_run(ssh, "pgrep -af 'artisan queue:(work|pool)' | head -20", check=False))
        print("--- config ---")
        print(sudo_run(
            ssh,
            "cd /var/www/apexone && php -r \"require 'vendor/autoload.php';"
            "\\$a=require 'bootstrap/app.php'; \\$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            "echo 'model='.config('openrouter.model').' workers='.config('queue.workers');\"" ,
            check=False,
        ))
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
