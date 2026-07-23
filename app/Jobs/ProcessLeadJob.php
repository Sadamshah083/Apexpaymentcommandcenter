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

    public int $tries = 12;
    public int $timeout = 180;

    public function backoff(): array
    {
        return [15, 30, 60, 90, 120, 180, 240, 300, 420, 600, 900];
    }

    public function __construct(
        public int $leadId,
        public ?string $customPrompt = null
    ) {
        $this->onQueue('enrichment');
    }

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

        // Upload-only imports must never enter the AI enrichment path.
        if ($workflow->isImportOnly()) {
            if ($lead->status === 'extracting') {
                SqliteConcurrency::retry(fn () => $lead->update(['status' => 'imported']));
            }

            return;
        }

        $workspace = $workflow->workspace;
        if (! $workspace) {
            return;
        }

        // Skip finished leads. Allow re-queue of "completed" rows that never got research data.
        if (in_array($lead->status, ['failed', 'pending_verification', 'enriched'], true)) {
            return;
        }
        if ($lead->status === 'completed' && $lead->researched_at) {
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
                $message = (string) ($result['error_message'] ?? 'Enrichment provider failed.');
                if ($this->isTransientProviderFailure($message)) {
                    $this->requeueTransient($lead, $message);

                    return;
                }

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

            if ($this->isTransientProviderFailure($e->getMessage())) {
                $this->requeueTransient($lead, $e->getMessage());

                return;
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

    protected function requeueTransient(WorkflowLead $lead, string $message): void
    {
        Log::warning("ProcessLeadJob releasing transient provider failure for lead {$lead->id}", [
            'attempt' => $this->attempts(),
            'error' => $message,
        ]);

        // Keep the lead queueable and wait for provider capacity instead of burn-failing.
        SqliteConcurrency::retry(fn () => $lead->update([
            'status' => 'imported',
            'error_message' => null,
        ]));

        $delay = max(5, (int) config('workflow_enrichment.openrouter_retry_delay_seconds', 20));
        $this->release($delay);
    }

    public function failed(?\Throwable $exception): void
    {
        $lead = WorkflowLead::find($this->leadId);
        if (! $lead || $lead->status === 'failed') {
            return;
        }

        DB::transaction(function () use ($lead, $exception) {
            $lead->update([
                'status' => 'failed',
                'error_message' => $exception?->getMessage() ?: 'Enrichment retry limit reached.',
            ]);

            $workflow = Workflow::lockForUpdate()->find($lead->workflow_id);
            if (! $workflow) {
                return;
            }

            $workflow->increment('failed_leads');
            $workflow->refresh();

            $stillProcessing = WorkflowLead::where('workflow_id', $workflow->id)
                ->whereIn('status', ['imported', 'extracting'])
                ->exists();
            if (! $stillProcessing && $workflow->status === 'extracting') {
                $workflow->update(['status' => 'completed']);
            }
        });
    }

    protected function isTransientProviderFailure(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'rate limit',
            'temporarily throttled',
            'too many requests',
            'provider returned error',
            'operation was aborted',
            'timeout',
            'timed out',
            'connection reset',
            'service unavailable',
            'http 429',
            'http 502',
            'http 503',
            'http 504',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
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
