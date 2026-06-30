<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\PipelineLeadReleaseService;
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
        PipelineLeadReleaseService $releaseService,
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

        if (in_array($lead->status, ['completed', 'failed', 'pending_verification', 'enriched'], true)) {
            return;
        }

        $workflow->refresh();
        if ($workflow->isPaused()) {
            if ($lead->status === 'extracting') {
                $lead->update(['status' => 'imported']);
            }

            return;
        }

        SqliteConcurrency::retry(fn () => $lead->update(['status' => 'extracting']));

        try {
            $result = $extractor->extract($lead, $this->customPrompt);

            $workflow->refresh();
            if (! $workflow || $workflow->isPaused()) {
                $lead->update(['status' => 'imported']);

                return;
            }

            if (($result['status'] ?? '') === 'failed') {
                SqliteConcurrency::retry(fn () => $lead->update($result));
                SqliteConcurrency::retry(fn () => $workflow->increment('failed_leads'));
                $this->syncWorkflowProgress($workflow, $workspace, $syncService);

                return;
            }

            $snapshot = $autoVerification->run($lead->fresh(), $workflow);

            if ($workflow->shouldAutoAssignSetters()) {
                SqliteConcurrency::retry(fn () => $lead->update(array_merge($result, [
                    'verification_snapshot' => $snapshot,
                    'pipeline_phase' => 'enriched',
                    'import_mode' => 'pipeline',
                    'status' => 'enriched',
                ])));
                SqliteConcurrency::retry(fn () => $workflow->increment('enriched_leads'));

                $actor = User::find($workspace->admin_id) ?? $workspace->admin;
                if ($actor) {
                    $releaseService->releaseToSetter($lead->fresh(), $actor);
                }
            } else {
                SqliteConcurrency::retry(fn () => $lead->update(array_merge($result, [
                    'status' => 'enriched',
                    'pipeline_phase' => 'enriched',
                    'verification_snapshot' => $snapshot,
                    'import_mode' => 'pipeline',
                ])));
                SqliteConcurrency::retry(fn () => $workflow->increment('enriched_leads'));
            }
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
                ->whereIn('status', ['imported', 'extracting'])
                ->exists();

            if ($stillProcessing) {
                return;
            }

            $enrichmentDone = $locked->total_leads > 0
                && ($locked->enriched_leads + $locked->failed_leads) >= $locked->total_leads;

            if ($enrichmentDone && $locked->status === 'extracting') {
                $locked->update(['status' => 'completed']);
                $syncService->record($workspace, 'workflow.completed', 'workflow', $locked->id, [
                    'name' => $locked->name,
                    'enriched_leads' => $locked->enriched_leads,
                    'processed_leads' => $locked->processed_leads,
                    'failed_leads' => $locked->failed_leads,
                ]);
            }
        });
    }
}
