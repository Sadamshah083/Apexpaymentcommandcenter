<?php

namespace App\Services\Pipeline;

use App\Models\LeadAssignment;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\SalesOps;
use App\Support\SqliteConcurrency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetterDistributionService
{
    public function distribute(Workspace $workspace, Collection $leads, ?Workflow $workflow = null): void
    {
        foreach ($leads as $lead) {
            $this->assignNext($workspace, $lead, $workflow);
        }
    }

    public function assignNext(Workspace $workspace, WorkflowLead $lead, ?Workflow $workflow = null): ?User
    {
        if ($lead->assigned_user_id) {
            return $lead->assignee ?? User::find($lead->assigned_user_id);
        }

        return SqliteConcurrency::retry(function () use ($workspace, $lead, $workflow) {
            return DB::transaction(function () use ($workspace, $lead, $workflow) {
                $workflowModel = Workflow::lockForUpdate()->find($workflow?->id ?? $lead->workflow_id);
                if (! $workflowModel) {
                    return null;
                }

                $leadModel = WorkflowLead::lockForUpdate()->find($lead->id);
                if (! $leadModel || $leadModel->assigned_user_id) {
                    return $leadModel?->assigned_user_id
                        ? User::find($leadModel->assigned_user_id)
                        : null;
                }

                $users = $this->resolvePool($workspace, $workflowModel);
                if ($users->isEmpty()) {
                    return null;
                }

                $userCount = $users->count();
                $cursor = (int) ($workflowModel->distribution_cursor ?? 0);
                $cap = (int) config('sales_ops.leads_per_setter', 500);

                $assignedUser = null;
                for ($i = 0; $i < $userCount; $i++) {
                    $candidate = $users->get(($cursor + $i) % $userCount);
                    $load = WorkflowLead::query()
                        ->where('assigned_user_id', $candidate->id)
                        ->where('pipeline_phase', 'with_setter')
                        ->count();

                    if ($load < $cap) {
                        $assignedUser = $candidate;
                        break;
                    }
                }

                if (! $assignedUser) {
                    return null;
                }

                $leadModel->update([
                    'assigned_user_id' => $assignedUser->id,
                    'assigned_setter_id' => $assignedUser->id,
                    'pipeline_phase' => 'with_setter',
                    'setter_status' => 'new',
                    'status' => 'completed',
                ]);

                $workflowModel->update(['distribution_cursor' => $cursor + 1]);

                $this->recordAssignment($leadModel, null, $assignedUser, 'with_setter', null);

                return $assignedUser;
            });
        });
    }

    protected function resolvePool(Workspace $workspace, ?Workflow $workflow): Collection
    {
        $query = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'appointment_setter')
            ->orderBy('users.id');

        $selectedUserIds = $workflow?->distribution_users;
        if (is_array($selectedUserIds) && ! empty($selectedUserIds)) {
            return $query
                ->whereIn('users.id', array_map('intval', $selectedUserIds))
                ->get();
        }

        return $query->get();
    }

    protected function recordAssignment(
        WorkflowLead $lead,
        ?User $from,
        ?User $to,
        string $phase,
        ?User $by,
    ): void {
        LeadAssignment::create([
            'workflow_lead_id' => $lead->id,
            'from_user_id' => $from?->id,
            'to_user_id' => $to?->id,
            'phase' => $phase,
            'assigned_by' => $by?->id,
        ]);
    }
}
