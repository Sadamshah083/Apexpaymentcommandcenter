<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Pipeline\LeadPipelineService;

class WorkflowLeadPolicy
{
    public function __construct(
        protected LeadPipelineService $pipeline,
    ) {}

    public function view(User $user, WorkflowLead $lead): bool
    {
        $workspace = $lead->workflow?->workspace;
        if (! $workspace) {
            return false;
        }

        return $this->pipeline->canView($user, $lead, $workspace);
    }

    public function update(User $user, WorkflowLead $lead): bool
    {
        return $this->view($user, $lead);
    }

    public function assignCloser(User $user, WorkflowLead $lead): bool
    {
        $workspace = $lead->workflow?->workspace;
        if (! $workspace || $lead->pipeline_phase !== 'appointment_settled') {
            return false;
        }

        return $user->isClosersTeamLead($workspace->id) || $user->canAccessAdminPortal($workspace->id);
    }
}
