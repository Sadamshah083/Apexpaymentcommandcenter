<?php

namespace App\Services\Pipeline;

use App\Models\LeadAssignment;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;

class CloserAssignmentService
{
    public function assign(Workspace $workspace, WorkflowLead $lead, User $closer, User $assignedBy): WorkflowLead
    {
        if ($lead->pipeline_phase !== 'appointment_settled') {
            throw ValidationException::withMessages([
                'lead' => 'Only appointment-settled leads can be assigned to closers.',
            ]);
        }

        if (! $closer->isCloser($workspace->id)) {
            throw ValidationException::withMessages([
                'closer' => 'Selected user is not a closer.',
            ]);
        }

        if (! $assignedBy->isClosersTeamLead($workspace->id) && ! $assignedBy->canAccessAdminPortal($workspace->id)) {
            throw ValidationException::withMessages([
                'lead' => 'Only the Closers Team Lead or an admin can assign closers.',
            ]);
        }

        $lead->update([
            'assigned_user_id' => $closer->id,
            'assigned_closer_id' => $closer->id,
            'pipeline_phase' => 'with_closer',
            'closer_status' => 'new',
        ]);

        LeadAssignment::create([
            'workflow_lead_id' => $lead->id,
            'from_user_id' => null,
            'to_user_id' => $closer->id,
            'phase' => 'with_closer',
            'assigned_by' => $assignedBy->id,
        ]);

        return $lead->fresh();
    }
}
