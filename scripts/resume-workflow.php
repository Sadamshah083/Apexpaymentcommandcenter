<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Workflow;
use App\Services\Workflow\WorkflowService;

$id = (int) ($argv[1] ?? 1);
$workflow = Workflow::find($id);

if (! $workflow) {
    fwrite(STDERR, "Workflow {$id} not found.\n");
    exit(1);
}

/** @var WorkflowService $service */
$service = app(WorkflowService::class);

$failed = $workflow->leads()->where('status', 'failed')->count();
$imported = $workflow->leads()->where('status', 'imported')->count();

echo "Before: status={$workflow->status} enriched={$workflow->enriched_leads} failed={$workflow->failed_leads} imported={$imported} failed_leads={$failed}\n";

if ($failed > 0) {
    $service->retryFailedLeads($workflow->fresh());
    echo "Retried {$failed} failed leads.\n";
}

$workflow = $workflow->fresh();
$imported = $workflow->leads()->where('status', 'imported')->count();

if ($imported > 0) {
    try {
        $service->startEnrichment($workflow);
        echo "Queued {$imported} imported leads for enrichment.\n";
    } catch (\Throwable $e) {
        echo "Enrichment start: {$e->getMessage()}\n";
    }
}

$workflow = $workflow->fresh();
echo "After: status={$workflow->status} enriched={$workflow->enriched_leads} failed={$workflow->failed_leads} jobs=".DB::table('jobs')->count()."\n";
