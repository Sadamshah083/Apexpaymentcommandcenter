<?php

namespace App\Services\Pipeline;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\SqliteConcurrency;
use Illuminate\Support\Facades\DB;

class PipelineLeadReleaseService
{
    public function __construct(
        protected SetterDistributionService $setterDistribution,
    ) {}

    /** @deprecated Use releaseToSetter() */
    public function autoReleaseToSetter(WorkflowLead $lead, User $actor): WorkflowLead
    {
        return $this->releaseToSetter($lead, $actor);
    }

    public function releaseToSetter(WorkflowLead $lead, User $actor, ?array $distributionUserIds = null): WorkflowLead
    {
        $lead->loadMissing('workflow.workspace');
        $workflow = $lead->workflow;
        $workspace = $workflow->workspace;

        if (! in_array($lead->status, ['enriched', 'pending_verification'], true)) {
            return $lead;
        }

        SqliteConcurrency::retry(function () use ($lead, $actor) {
            DB::transaction(function () use ($lead, $actor) {
                $lockedLead = WorkflowLead::lockForUpdate()->find($lead->id);
                if (! $lockedLead || $lockedLead->assigned_user_id) {
                    return;
                }

                $lockedLead->update([
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                ]);
            });
        });

        $lead->refresh();
        $this->setterDistribution->assignNext($workspace, $lead->fresh(), $workflow, $distributionUserIds);

        SqliteConcurrency::retry(function () use ($workflow) {
            DB::transaction(function () use ($workflow) {
                $lockedWorkflow = Workflow::lockForUpdate()->find($workflow->id);
                if ($lockedWorkflow) {
                    $lockedWorkflow->increment('processed_leads');
                }
            });
        });

        return $lead->fresh();
    }

    public function distributeEnrichedLeads(Workflow $workflow, Workspace $workspace, User $actor): int
    {
        $leads = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'enriched')
            ->whereNull('assigned_user_id')
            ->orderBy('row_number')
            ->get();

        $count = 0;
        foreach ($leads as $lead) {
            $this->releaseToSetter($lead, $actor);
            $count++;
        }

        return $count;
    }
}
