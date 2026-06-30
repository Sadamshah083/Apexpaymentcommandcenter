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

    public function autoReleaseToSetter(WorkflowLead $lead, User $actor): WorkflowLead
    {
        $lead->loadMissing('workflow.workspace');
        $workflow = $lead->workflow;
        $workspace = $workflow->workspace;

        if ($workflow->processing_mode === 'store_only') {
            return $lead;
        }

        SqliteConcurrency::retry(function () use ($lead, $actor, $workflow, $workspace) {
            DB::transaction(function () use ($lead, $actor, $workflow, $workspace) {
                $lockedLead = WorkflowLead::lockForUpdate()->find($lead->id);
                if (! $lockedLead || $lockedLead->pipeline_phase === 'with_setter') {
                    return;
                }

                $lockedLead->update([
                    'status' => 'completed',
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                    'pipeline_phase' => 'enriching',
                    'import_mode' => 'pipeline',
                ]);

                $lockedWorkflow = Workflow::lockForUpdate()->find($workflow->id);
                if ($lockedWorkflow) {
                    $lockedWorkflow->increment('processed_leads');
                }
            });
        });

        $lead->refresh();
        $this->setterDistribution->assignNext($workspace, $lead->fresh(), $workflow);

        return $lead->fresh();
    }
}
