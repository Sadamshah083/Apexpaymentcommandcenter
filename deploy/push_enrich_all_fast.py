#!/usr/bin/env python3
"""Fix enriched counters, speed OpenRouter fallback, requeue incomplete Auto repair imports."""

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

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run, sudo_run_batch, upload_files

FILES = [
    "app/Models/WorkflowLead.php",
    "app/Jobs/ProcessLeadJob.php",
    "app/Services/Workflow/WorkflowDashboardService.php",
    "app/Services/Workflow/WorkflowService.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "config/workflow_enrichment.php",
]

REQUEUE = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessLeadJob;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

// Clear stuck provider lock / rate limiter keys
try {
    Cache::lock('workflow-enrichment:openrouter-active', 1)->forceRelease();
} catch (Throwable $e) {}
RateLimiter::clear('workflow-enrichment:openrouter-fallback');

DB::table('jobs')->delete();
DB::table('failed_jobs')->where('payload', 'like', '%ProcessLeadJob%')->delete();

$ids = [18, 22, 23];
$totalQueued = 0;

foreach ($ids as $id) {
    $workflow = Workflow::find($id);
    if (! $workflow) {
        echo "missing {$id}\n";
        continue;
    }

    // Reset rows that never finished research so they can run again.
    $pendingIds = WorkflowLead::query()
        ->where('workflow_id', $id)
        ->where(function ($q) {
            $q->whereNull('researched_at')
              ->orWhere('status', 'failed');
        })
        ->orderBy('row_number')
        ->pluck('id');

    WorkflowLead::whereIn('id', $pendingIds)->update([
        'status' => 'imported',
        'error_message' => null,
    ]);

    $enriched = WorkflowLead::where('workflow_id', $id)->enrichmentSucceeded()->count();
    $failed = WorkflowLead::where('workflow_id', $id)->where('status', 'failed')->count();
    $pending = $pendingIds->count();

    $workflow->update([
        'status' => $pending > 0 ? 'extracting' : 'completed',
        'enriched_leads' => $enriched,
        'failed_leads' => $failed,
    ]);

    echo "wf{$id} enriched={$enriched} failed={$failed} pending={$pending} status={$workflow->fresh()->status}\n";

    // Pace ~8/min to stay under free OpenRouter RPM while Gemini is depleted.
    foreach ($pendingIds as $i => $leadId) {
        ProcessLeadJob::dispatch((int) $leadId, $workflow->custom_prompt)
            ->delay(now()->addSeconds((int) floor($i * 7.5)));
        $totalQueued++;
    }
}

echo "queued={$totalQueued} rpm=" . config('workflow_enrichment.openrouter_fallback_rpm')
    . " retry=" . config('workflow_enrichment.openrouter_retry_delay_seconds')
    . " model=" . config('openrouter.model') . "\n";
echo "jobs=" . DB::table('jobs')->count() . "\n";
"""


def upsert_env(ssh, key: str, value: str) -> None:
    sudo_run(
        ssh,
        f"cd {REMOTE_APP} && "
        f"if grep -q '^{key}=' .env; then "
        f"sed -i 's|^{key}=.*|{key}={value}|' .env; "
        f"else echo '{key}={value}' >> .env; fi",
    )


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    print("Tuning enrichment env for faster OpenRouter fallback...")
    upsert_env(ssh, "WORKFLOW_OPENROUTER_FALLBACK_RPM", "10")
    upsert_env(ssh, "WORKFLOW_OPENROUTER_RETRY_DELAY", "8")
    upsert_env(ssh, "WORKFLOW_OPENROUTER_MAX_TOKENS", "1536")
    upsert_env(ssh, "WORKFLOW_WEB_SEARCH_QUERIES", "0")
    upsert_env(ssh, "WORKFLOW_FOLLOW_UP_ENABLED", "false")
    # Keep single worker while OpenRouter lock is serial; more workers just fight the lock.
    upsert_env(ssh, "QUEUE_WORKERS", "1")

    local = ROOT / "deploy" / "_requeue_enrich_tmp.php"
    local.write_text(REQUEUE, encoding="utf-8")
    upload_files(ssh, [(local, "storage/app/_requeue_enrich.php")], app_root=REMOTE_APP)

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_requeue_enrich.php",
        f"rm -f {REMOTE_APP}/storage/app/_requeue_enrich.php",
    ])
    local.unlink(missing_ok=True)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    print(sudo_run(ssh, "ps aux | grep 'queue:work' | grep -v grep | head -5", check=False))
    ssh.close()
    print("Enrichment requeue deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
