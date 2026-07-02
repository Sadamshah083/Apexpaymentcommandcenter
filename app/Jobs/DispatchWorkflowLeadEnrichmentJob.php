<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatches lead enrichment in waves so large imports do not flood the queue or SQLite.
 */
class DispatchWorkflowLeadEnrichmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $workflowId,
        public int $offset = 0,
        public int $batchSize = 100,
    ) {}

    public function handle(): void
    {
        $workflow = Workflow::find($this->workflowId);
        if (! $workflow || $workflow->isPaused()) {
            return;
        }

        if ($this->offset === 0) {
            $workflow->update(['status' => 'extracting']);
        }

        $batchSize = max(1, (int) config('workflow.enrichment_dispatch_batch', $this->batchSize));

        $leadIds = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'imported')
            ->orderBy('row_number')
            ->skip($this->offset)
            ->take($batchSize)
            ->pluck('id');

        foreach ($leadIds as $leadId) {
            ProcessLeadJob::dispatch($leadId, $workflow->custom_prompt);
        }

        if ($leadIds->count() === $batchSize) {
            $delay = max(0, (int) config('workflow.enrichment_dispatch_delay', 0));
            $next = self::dispatch($workflow->id, $this->offset + $batchSize, $batchSize);
            if ($delay > 0) {
                $next->delay(now()->addSeconds($delay));
            }

            return;
        }
    }
}
