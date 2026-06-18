<?php

namespace App\Jobs;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Workflow\WorkflowExtractor;
use App\Services\Workflow\WorkflowLeadDistributor;
use App\Services\Workspace\WorkspaceSyncService;
use App\Http\Controllers\PushNotificationController;
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

    public function handle(WorkflowExtractor $extractor, WorkflowLeadDistributor $distributor, WorkspaceSyncService $syncService): void
    {
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

        if (in_array($lead->status, ['completed', 'failed'], true)) {
            return;
        }

        $workflow->refresh();
        if ($workflow->isPaused()) {
            if ($lead->status === 'extracting') {
                $lead->update(['status' => 'pending']);
            }

            return;
        }

        $hadAssignee = (bool) $lead->assigned_user_id;
        if (! $lead->assigned_user_id) {
            SqliteConcurrency::retry(fn () => $distributor->assignNext($workspace, $lead->fresh(), $workflow));
            $lead->refresh();
        }

        if (! $hadAssignee && $lead->assigned_user_id) {
            $this->notifyAssignee($lead);
        }

        SqliteConcurrency::retry(fn () => $lead->update(['status' => 'extracting']));

        try {
            $result = $extractor->extract($lead, $this->customPrompt);

            $workflow->refresh();
            if (! $workflow || $workflow->isPaused()) {
                $lead->update(['status' => 'pending']);

                return;
            }

            $lead->update($result);

            if ($result['status'] === 'completed') {
                $workflow->increment('processed_leads');
            } else {
                $workflow->increment('failed_leads');
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

    protected function notifyAssignee(WorkflowLead $lead): void
    {
        if (! $lead->assigned_user_id) {
            return;
        }

        try {
            PushNotificationController::sendPushNotification(
                $lead->assigned_user_id,
                'New lead assigned',
                'You have been assigned: '.$lead->business_name,
                route('portal.leads.show', $lead->id)
            );
        } catch (\Exception $e) {
            Log::warning("Push notification failed for lead {$lead->id}: ".$e->getMessage());
        }
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
