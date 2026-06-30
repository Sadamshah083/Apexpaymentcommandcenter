<?php

namespace App\Services\Pipeline;

use App\Models\LeadAssignment;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadActivityService;
use App\Support\SalesOps;
use Illuminate\Validation\ValidationException;

class LeadPipelineService
{
    public function __construct(
        protected LeadActivityService $activities,
    ) {}

    public function canView(User $user, WorkflowLead $lead, Workspace $workspace): bool
    {
        if ($user->canAccessAdminPortal($workspace->id)) {
            return true;
        }

        $role = $user->getWorkspaceRole($workspace->id);

        return match ($role) {
            'appointment_setter' => $lead->pipeline_phase === 'with_setter'
                && (int) $lead->assigned_user_id === $user->id,
            'appointment_setter_team_lead' => in_array($lead->pipeline_phase, ['with_setter', 'appointment_settled', 'with_closer', 'closed'], true)
                && $lead->assigned_setter_id
                && $this->isSetterOnTeam($workspace, (int) $lead->assigned_setter_id),
            'closers_team_lead' => in_array($lead->pipeline_phase, ['appointment_settled', 'with_closer', 'closed'], true),
            'closer' => $lead->pipeline_phase === 'with_closer'
                && (int) $lead->assigned_user_id === $user->id,
            default => false,
        };
    }

    public function updateSetterStatus(User $user, WorkflowLead $lead, Workspace $workspace, string $status, ?string $notes = null): WorkflowLead
    {
        if (! in_array($status, array_keys(config('sales_ops.setter_statuses', [])), true)) {
            throw ValidationException::withMessages(['setter_status' => 'Invalid setter status.']);
        }

        if ($lead->pipeline_phase !== 'with_setter') {
            throw ValidationException::withMessages(['lead' => 'This lead is not in the setter phase.']);
        }

        if (! $user->isAppointmentSetter($workspace->id) && ! $user->canAccessAdminPortal($workspace->id)) {
            throw ValidationException::withMessages(['lead' => 'You cannot update this lead.']);
        }

        if ($user->isAppointmentSetter($workspace->id) && (int) $lead->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages(['lead' => 'This lead is not assigned to you.']);
        }

        $lead->update(['setter_status' => $status]);

        if ($notes) {
            $lead->update(['notes' => $notes]);
            $this->activities->log($lead, $user, 'note', null, $notes);
        }

        if ($status === 'appointment_settled') {
            $this->handoffToClosersTeamLead($lead, $user, $notes);
        }

        return $lead->fresh();
    }

    public function updateCloserStatus(User $user, WorkflowLead $lead, Workspace $workspace, string $status, ?string $notes = null): WorkflowLead
    {
        if (! in_array($status, array_keys(config('sales_ops.closer_statuses', [])), true)) {
            throw ValidationException::withMessages(['closer_status' => 'Invalid closer status.']);
        }

        if ($status === 'sale_made' && ! $user->isCloser($workspace->id) && ! $user->canAccessAdminPortal($workspace->id)) {
            throw ValidationException::withMessages(['closer_status' => 'Only closers can mark a sale as made.']);
        }

        if ($lead->pipeline_phase !== 'with_closer') {
            throw ValidationException::withMessages(['lead' => 'This lead is not in the closer phase.']);
        }

        if ($user->isCloser($workspace->id) && (int) $lead->assigned_user_id !== $user->id) {
            throw ValidationException::withMessages(['lead' => 'This lead is not assigned to you.']);
        }

        $lead->update(['closer_status' => $status]);

        if ($notes) {
            $lead->update(['notes' => $notes]);
            $this->activities->log($lead, $user, 'note', null, $notes);
        }

        if (in_array($status, ['sale_made', 'closed_lost'], true)) {
            $lead->update(['pipeline_phase' => 'closed']);
        }

        return $lead->fresh();
    }

    protected function handoffToClosersTeamLead(WorkflowLead $lead, User $user, ?string $notes): void
    {
        $lead->update([
            'pipeline_phase' => 'appointment_settled',
            'assigned_user_id' => null,
            'assigned_setter_id' => $lead->assigned_user_id ?: $lead->assigned_setter_id,
            'appointment_settled_at' => now(),
            'handoff_notes' => $notes,
        ]);

        LeadAssignment::create([
            'workflow_lead_id' => $lead->id,
            'from_user_id' => $user->id,
            'to_user_id' => null,
            'phase' => 'appointment_settled',
            'assigned_by' => $user->id,
            'meta' => ['notes' => $notes],
        ]);
    }

    protected function isSetterOnTeam(Workspace $workspace, int $setterUserId): bool
    {
        return $workspace->users()
            ->where('users.id', $setterUserId)
            ->wherePivot('role', 'appointment_setter')
            ->exists();
    }
}
