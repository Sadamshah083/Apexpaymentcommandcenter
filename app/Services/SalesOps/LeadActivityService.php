<?php

namespace App\Services\SalesOps;

use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Services\Workspace\WorkspaceSyncService;
use App\Support\SalesOps;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeadActivityService
{
    public function __construct(
        protected DiscoveryQualificationService $discovery,
        protected WorkspaceSyncService $syncService,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(WorkflowLead $lead, User $user, string $type, ?string $outcome = null, ?string $notes = null, array $metadata = []): LeadActivity
    {
        $allowed = array_keys(config('sales_ops.activity_types', []));
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages(['type' => 'Invalid activity type.']);
        }

        $activity = DB::transaction(function () use ($lead, $user, $type, $outcome, $notes, $metadata) {
            $lockedLead = WorkflowLead::lockForUpdate()->find($lead->id);
            if (! $lockedLead) {
                throw ValidationException::withMessages(['lead' => 'Lead not found.']);
            }

            $activity = LeadActivity::create([
                'workflow_lead_id' => $lockedLead->id,
                'user_id' => $user->id,
                'type' => $type,
                'outcome' => $outcome,
                'notes' => $notes,
                'metadata' => $metadata ?: null,
            ]);

            $this->applyActivitySideEffects($lockedLead, $type, $outcome);

            return $activity;
        });

        $lead->refresh();
        $lead->loadMissing('workflow.workspace');
        $this->syncService->record(
            $lead->workflow->workspace,
            'lead.activity',
            'workflow_lead',
            $lead->id,
            [
                'type' => $type,
                'business_name' => $lead->business_name,
                'contact_attempts' => $lead->contact_attempts,
                'tier' => $lead->tier,
                'stage' => $lead->stage,
            ],
            $user->id
        );

        return $activity;
    }

    protected function applyActivitySideEffects(WorkflowLead $lead, string $type, ?string $outcome): void
    {
        $updates = ['last_contacted_at' => now()];

        if (in_array($type, ['dial', 'conversation', 'decision_maker', 'sms', 'email'], true)) {
            $updates['contact_attempts'] = $lead->contact_attempts + 1;
        }

        if ($type === 'dial' && $lead->stage === 'new_lead') {
            $updates['stage'] = 'attempted_contact';
        }

        if ($type === 'conversation' && in_array($lead->stage, ['new_lead', 'attempted_contact'], true)) {
            $updates['stage'] = 'connected';
        }

        if ($type === 'meeting_booked') {
            $updates['stage'] = 'meeting_scheduled';
            $updates['schedule_at'] = $updates['schedule_at'] ?? now();
        }

        if ($type === 'discovery' && $this->discovery->isComplete($lead)) {
            $updates['stage'] = 'discovery_completed';
            $updates['discovery_completed_at'] = now();
        }

        $attempts = $updates['contact_attempts'] ?? $lead->contact_attempts;
        $updates['tier'] = SalesOps::tierFromAttempts((int) $attempts, (bool) $lead->is_nurture);

        $lead->update($updates);
    }
}
