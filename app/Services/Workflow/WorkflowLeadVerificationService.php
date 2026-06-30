<?php

namespace App\Services\Workflow;

use App\Http\Controllers\PushNotificationController;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\Pipeline\SetterDistributionService;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\SqliteConcurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WorkflowLeadVerificationService
{
    public function __construct(
        protected SetterDistributionService $distributor,
        protected WorkspaceSyncService $syncService,
    ) {}

    public function approve(WorkflowLead $lead, User $user): WorkflowLead
    {
        if ($lead->status !== 'pending_verification') {
            throw ValidationException::withMessages([
                'lead' => 'This lead is not awaiting manual verification.',
            ]);
        }

        $lead->loadMissing('workflow.workspace');
        $workflow = $lead->workflow;
        $workspace = $workflow->workspace;

        SqliteConcurrency::retry(function () use ($lead, $user, $workflow, $workspace) {
            DB::transaction(function () use ($lead, $user, $workflow, $workspace) {
                $lockedLead = WorkflowLead::lockForUpdate()->find($lead->id);
                if (! $lockedLead || $lockedLead->status !== 'pending_verification') {
                    return;
                }

                $lockedWorkflow = Workflow::lockForUpdate()->find($workflow->id);
                if (! $lockedWorkflow) {
                    return;
                }

                if (! $lockedLead->assigned_user_id) {
                    $this->distributor->assignNext($workspace, $lockedLead, $lockedWorkflow);
                    $lockedLead->refresh();
                }

                $lockedLead->update([
                    'status' => 'completed',
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $user->id,
                    'rejection_reason' => null,
                    'pipeline_phase' => 'with_setter',
                    'setter_status' => $lockedLead->setter_status ?: 'new',
                    'import_mode' => 'pipeline',
                ]);

                $lockedWorkflow->increment('processed_leads');
            });
        });

        $lead->refresh();
        $this->notifyAssigneeIfNeeded($lead);
        $this->recordVerificationEvent($lead, $user, 'lead.verified');

        return $lead;
    }

    public function reject(WorkflowLead $lead, User $user, ?string $reason = null): WorkflowLead
    {
        if ($lead->status !== 'pending_verification') {
            throw ValidationException::withMessages([
                'lead' => 'This lead is not awaiting manual verification.',
            ]);
        }

        $lead->loadMissing('workflow.workspace');

        SqliteConcurrency::retry(function () use ($lead, $user, $reason) {
            DB::transaction(function () use ($lead, $user, $reason) {
                $lockedLead = WorkflowLead::lockForUpdate()->find($lead->id);
                if (! $lockedLead || $lockedLead->status !== 'pending_verification') {
                    return;
                }

                $lockedWorkflow = Workflow::lockForUpdate()->find($lead->workflow_id);
                if (! $lockedWorkflow) {
                    return;
                }

                $lockedLead->update([
                    'status' => 'failed',
                    'verification_status' => 'rejected',
                    'verified_at' => now(),
                    'verified_by' => $user->id,
                    'rejection_reason' => $reason,
                    'error_message' => $reason ?: 'Rejected during manual verification',
                ]);

                $lockedWorkflow->increment('failed_leads');
            });
        });

        $lead->refresh();
        $this->recordVerificationEvent($lead, $user, 'lead.rejected');

        return $lead;
    }

    /**
     * @param  array<int>  $leadIds
     */
    public function approveMany(Workflow $workflow, array $leadIds, User $user): int
    {
        $approved = 0;

        $workflow->leads()
            ->whereIn('id', $leadIds)
            ->where('status', 'pending_verification')
            ->orderBy('row_number')
            ->each(function (WorkflowLead $lead) use ($user, &$approved) {
                $this->approve($lead, $user);
                $approved++;
            });

        return $approved;
    }

    protected function notifyAssigneeIfNeeded(WorkflowLead $lead): void
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
        } catch (\Throwable $e) {
            Log::warning('Verification approval push notification failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function recordVerificationEvent(WorkflowLead $lead, User $user, string $eventType): void
    {
        $lead->loadMissing('workflow.workspace');

        $this->syncService->record(
            $lead->workflow->workspace,
            $eventType,
            'workflow_lead',
            $lead->id,
            [
                'business_name' => $lead->business_name,
                'status' => $lead->status,
                'verification_status' => $lead->verification_status,
                'assigned_user_id' => $lead->assigned_user_id,
            ],
            $user->id
        );
    }
}
