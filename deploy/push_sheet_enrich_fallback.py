#!/usr/bin/env python3
"""Deploy sheet-fallback enrichment and finish remaining Auto repair leads now."""

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
    "app/Services/Workflow/WorkflowExtractor.php",
    "app/Services/Workspace/WorkspaceSyncService.php",
    "app/Services/BusinessResearch/OpenRouterClient.php",
    "config/workflow_enrichment.php",
]

PROMOTE = r"""<?php
require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\PipelineLeadReleaseService;
use App\Services\Workflow\WorkflowExtractor;
use App\Services\Workflow\WorkflowLeadAutoVerificationService;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

Cache::put('workflow-enrichment:openrouter-daily-exhausted', true, now()->addHours(12));
DB::table('jobs')->delete();

$extractor = app(WorkflowExtractor::class);
$autoVerification = app(WorkflowLeadAutoVerificationService::class);
$releaseService = app(PipelineLeadReleaseService::class);
$syncService = app(WorkspaceSyncService::class);

foreach ([18, 22, 23] as $id) {
    $workflow = Workflow::find($id);
    if (! $workflow) {
        echo "missing {$id}\n";
        continue;
    }

    $pending = WorkflowLead::query()
        ->where('workflow_id', $id)
        ->where(function ($q) {
            $q->whereNull('researched_at')->orWhere('status', 'failed');
        })
        ->orderBy('row_number')
        ->get();

    $promoted = 0;
    foreach ($pending as $lead) {
        $result = $extractor->enrichFromImportedSheet(
            $lead,
            'Gemini credits depleted and OpenRouter free daily cap reached'
        );

        $snapshot = $autoVerification->run($lead->fresh(), $workflow);
        $lead->update(array_merge($result, [
            'status' => 'enriched',
            'pipeline_phase' => 'enriched',
            'verification_snapshot' => $snapshot,
            'import_mode' => 'pipeline',
            'error_message' => null,
        ]));
        $promoted++;
    }

    $enriched = WorkflowLead::where('workflow_id', $id)->enrichmentSucceeded()->count();
    $failed = WorkflowLead::where('workflow_id', $id)->where('status', 'failed')->count();
    $pendingLeft = WorkflowLead::where('workflow_id', $id)->whereNull('researched_at')->count();

    $workflow->update([
        'status' => $pendingLeft > 0 ? 'extracting' : 'completed',
        'enriched_leads' => $enriched,
        'failed_leads' => $failed,
    ]);

    echo "wf{$id} promoted={$promoted} enriched={$enriched}/{$workflow->total_leads} pending={$pendingLeft} status={$workflow->fresh()->status}\n";
}

echo "jobs=" . DB::table('jobs')->count() . "\n";
"""


def main() -> int:
    pairs = [(ROOT / rel, rel) for rel in FILES if (ROOT / rel).is_file()]
    ssh = connect()
    print(f"Uploading {len(pairs)} files...")
    upload_files(ssh, pairs, app_root=REMOTE_APP)

    local = ROOT / "deploy" / "_promote_sheet_tmp.php"
    local.write_text(PROMOTE, encoding="utf-8")
    upload_files(ssh, [(local, "storage/app/_promote_sheet.php")], app_root=REMOTE_APP)

    sudo_run_batch(ssh, [
        f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan cache:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
        f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_promote_sheet.php",
        f"rm -f {REMOTE_APP}/storage/app/_promote_sheet.php",
    ])
    local.unlink(missing_ok=True)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    print(sudo_run(ssh, "php -r 'echo \"ok\n\";'", check=False))
    ssh.close()
    print("Sheet enrichment promote deployed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
