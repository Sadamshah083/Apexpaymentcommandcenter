<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Workflow\WorkflowExtractor;
use App\Services\Workflow\WorkflowLeadAutoVerificationService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\SqliteConcurrency;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessLeadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 180;

    public function backoff(): array
    {
        return [2, 5, 10, 20, 30];
    }

    public function __construct(
        public int $leadId,
        public ?string $customPrompt = null
    ) {}

    public function handle(
        WorkflowExtractor $extractor,
        WorkflowLeadAutoVerificationService $autoVerification,
        WorkspaceSyncService $syncService
    ): void {
        $lead = WorkflowLead::find($this->leadId);
        if (! $lead) {
            return;
        }

        $workflow = Workflow::find($lead->workflow_id);
        if (! $workflow) {
            return;
        }

        $workspace = $workflow->workspace;
        if (! $workspace) {
            return;
        }

        if (in_array($lead->status, ['completed', 'failed', 'pending_verification'], true)) {
            return;
        }

        $workflow->refresh();
        if ($workflow->isPaused()) {
            if ($lead->status === 'extracting') {
                $lead->update(['status' => 'pending']);
            }

            return;
        }

        SqliteConcurrency::retry(fn () => $lead->update(['status' => 'extracting']));

        try {
            $result = $extractor->extract($lead, $this->customPrompt);

            $workflow->refresh();
            if (! $workflow || $workflow->isPaused()) {
                $lead->update(['status' => 'pending']);

                return;
            }

            if (($result['status'] ?? '') === 'failed') {
                SqliteConcurrency::retry(fn () => $lead->update($result));
                SqliteConcurrency::retry(fn () => $workflow->increment('failed_leads'));
                $this->syncWorkflowProgress($workflow, $workspace, $syncService);

                return;
            }

            $snapshot = $autoVerification->run($lead->fresh(), $workflow);

            SqliteConcurrency::retry(fn () => $lead->update(array_merge($result, [
                'status' => 'pending_verification',
                'verification_status' => 'pending',
                'verification_snapshot' => $snapshot,
            ])));
        } catch (\Exception $e) {
            if (SqliteConcurrency::causedByLock($e)) {
                throw $e;
            }

            Log::error("ProcessLeadJob failed for lead {$lead->id}: ".$e->getMessage());
            SqliteConcurrency::retry(fn () => $lead->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]));
            SqliteConcurrency::retry(fn () => $workflow->increment('failed_leads'));
        }

        $this->syncWorkflowProgress($workflow, $workspace, $syncService);
    }

    protected function syncWorkflowProgress(Workflow $workflow, $workspace, WorkspaceSyncService $syncService): void
    {
        DB::transaction(function () use ($workflow, $workspace, $syncService) {
            $locked = Workflow::lockForUpdate()->find($workflow->id);
            if (! $locked) {
                return;
            }

            if ($locked->isPaused()) {
                return;
            }

            $stillProcessing = WorkflowLead::where('workflow_id', $locked->id)
                ->whereIn('status', ['pending', 'extracting'])
                ->exists();

            if ($stillProcessing) {
                return;
            }

            $finished = $locked->total_leads > 0
                && ($locked->processed_leads + $locked->failed_leads) >= $locked->total_leads;

            if ($finished && $locked->status !== 'completed') {
                $locked->update(['status' => 'completed']);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $locked->id, [
                    'name' => $locked->name,
                    'processed_leads' => $locked->processed_leads,
                    'failed_leads' => $locked->failed_leads,
                ]);

                return;
            }

            // UI refreshes from poll state; skip per-lead workflow.updated noise.
        });
    }
}
