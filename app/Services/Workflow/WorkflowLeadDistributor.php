<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Support\SqliteConcurrency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkflowLeadDistributor
{
    /**
     * Assign leads using a locked round-robin cursor so parallel jobs distribute evenly.
     */
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

            $users = $this->resolveDistributionPool($workspace, $workflowModel);
            if ($users->isEmpty()) {
                $users = collect([$workspace->admin])->filter();
            }

            if ($users->isEmpty()) {
                return null;
            }

            $userCount = $users->count();
            $cursor = (int) ($workflowModel->distribution_cursor ?? 0);
            $cap = (int) config('sales_ops.leads_per_sdr', 500);

            $assignedUser = null;
            for ($i = 0; $i < $userCount; $i++) {
                $candidate = $users->get(($cursor + $i) % $userCount);
                $load = WorkflowLead::query()
                    ->where('assigned_user_id', $candidate->id)
                    ->where('status', 'completed')
                    ->whereNotIn('stage', ['closed_won', 'closed_lost'])
                    ->count();

                if ($load < $cap) {
                    $assignedUser = $candidate;
                    break;
                }
            }

            if (! $assignedUser) {
                return null;
            }

            $leadModel->update(['assigned_user_id' => $assignedUser->id]);
            $workflowModel->update(['distribution_cursor' => $cursor + 1]);

            return $assignedUser;
            });
        });
    }

    public function assignUnassignedPending(Workspace $workspace, Workflow $workflow): int
    {
        $assigned = 0;

        $workflow->leads()
            ->whereNull('assigned_user_id')
            ->orderBy('row_number')
            ->each(function (WorkflowLead $lead) use ($workspace, $workflow, &$assigned) {
                if ($this->assignNext($workspace, $lead, $workflow->fresh())) {
                    $assigned++;
                }
            });

        return $assigned;
    }

    protected function resolveDistributionPool(Workspace $workspace, ?Workflow $workflow): Collection
    {
        $query = $workspace->users()
            ->wherePivot('status', 'active')
            ->orderBy('users.id');

        $selectedUserIds = $workflow?->distribution_users;
        if (is_array($selectedUserIds) && ! empty($selectedUserIds)) {
            return $query
                ->whereIn('users.id', array_map('intval', $selectedUserIds))
                ->get();
        }

        $marketers = (clone $query)->wherePivotIn('role', ['marketer', 'sdr'])->get();
        if ($marketers->isNotEmpty()) {
            return $marketers;
        }

        return $query->wherePivotIn('role', ['marketer', 'sdr', 'admin'])->get();
    }
}
