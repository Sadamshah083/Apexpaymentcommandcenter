<?php

/**
 * Unstick enrichment: clear blocked maps jobs, resume zero-lead imports, re-queue processing.
 * Enrichment will use OpenRouter while Gemini project access is denied.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Jobs\ProcessWorkflowJob;
use App\Models\Workflow;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

$provider = app(WorkflowProviderStatusService::class);
$provider->getEnrichmentStatus(true, true);
$health = $provider->getGeminiHealth(false);
echo 'Gemini state='.($health['state'] ?? '?').' msg='.substr((string) ($health['message'] ?? ''), 0, 120).PHP_EOL;
echo 'OpenRouter configured='.(filled(config('openrouter.api_key')) ? 'yes' : 'no').PHP_EOL;

// Drop stuck Maps scrape jobs so ProcessWorkflowJob can run.
$deletedMaps = 0;
foreach (DB::table('jobs')->orderBy('id')->get() as $job) {
    $payload = json_decode($job->payload, true);
    $name = (string) ($payload['displayName'] ?? '');
    if (str_contains($name, 'RunMapsScrapeJob')) {
        DB::table('jobs')->where('id', $job->id)->delete();
        $deletedMaps++;
    }
}
echo "Deleted stuck Maps jobs={$deletedMaps}\n";

// Also clear failed maps jobs so they don't clutter.
$clearedFailed = DB::table('failed_jobs')
    ->where('payload', 'like', '%RunMapsScrapeJob%')
    ->orWhere('exception', 'like', '%Maps scraper%')
    ->delete();
echo "Cleared failed Maps jobs={$clearedFailed}\n";

$ids = Workflow::query()
    ->whereIn('id', [31, 32, 33, 34, 35])
    ->orWhere(function ($q) {
        $q->where('total_leads', 0)
            ->whereIn('status', ['paused', 'extracting', 'failed'])
            ->where('created_at', '>=', now()->subDays(2));
    })
    ->pluck('id')
    ->unique()
    ->values();

echo 'Target workflows: '.$ids->implode(',').PHP_EOL;

foreach ($ids as $id) {
    $wf = Workflow::find($id);
    if (! $wf) {
        continue;
    }

    // Remove any pending ProcessWorkflowJob duplicates for this workflow.
    foreach (DB::table('jobs')->get() as $job) {
        $payload = json_decode($job->payload, true);
        $command = $payload['data']['command'] ?? null;
        if (! is_string($command)) {
            continue;
        }
        if (! str_contains($command, 'ProcessWorkflowJob')) {
            continue;
        }
        try {
            $obj = unserialize($command);
            if ($obj instanceof ProcessWorkflowJob && (int) $obj->workflowId === (int) $id) {
                DB::table('jobs')->where('id', $job->id)->delete();
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    $wf->update([
        'status' => 'extracting',
        'error_message' => null,
        'ingestion_complete' => false,
        'ingestion_row_offset' => 0,
        'total_leads' => 0,
        'enriched_count' => 0,
        'failed_leads' => 0,
        'processed_leads' => 0,
    ]);

    // Drop any empty prior lead rows for a clean re-ingest.
    DB::table('workflow_leads')->where('workflow_id', $wf->id)->delete();

    ProcessWorkflowJob::dispatch($wf->id, $wf->file_path);
    echo "Re-queued workflow #{$wf->id} ({$wf->name}) file={$wf->file_path}\n";
}

Cache::forget('workflow.gemini_health');
Artisan::call('queue:restart');
echo Artisan::output();

echo 'PENDING_JOBS_NOW='.DB::table('jobs')->count().PHP_EOL;
echo "DONE\n";
