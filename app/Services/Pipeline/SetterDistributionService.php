<?php

namespace App\Services\Pipeline;

use App\Models\LeadAssignment;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadActivityService;
use App\Support\SqliteConcurrency;
use App\Support\WorkflowAssignmentRoles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SetterDistributionService
{
    public function __construct(
        protected LeadActivityService $activities,
    ) {}

    public function distribute(Workspace $workspace, Collection $leads, ?Workflow $workflow = null): void
    {
        foreach ($leads as $lead) {
            $this->assignNext($workspace, $lead, $workflow);
        }
    }

    public function assignNext(Workspace $workspace, WorkflowLead $lead, ?Workflow $workflow = null, ?array $distributionUserIds = null): ?User
    {
        if ($lead->assigned_user_id) {
            return $lead->assignee ?? User::find($lead->assigned_user_id);
        }

        return SqliteConcurrency::retry(function () use ($workspace, $lead, $workflow, $distributionUserIds) {
            return DB::transaction(function () use ($workspace, $lead, $workflow, $distributionUserIds) {
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

                $users = $this->resolvePool($workspace, $workflowModel, $distributionUserIds);
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

                $this->activities->logStatusChange(
                    $leadModel->fresh(),
                    $assignedUser,
                    'setter',
                    null,
                    'new',
                    'Lead assigned to appointment setter'
                );

                return $assignedUser;
            });
        });
    }

    protected function resolvePool(Workspace $workspace, ?Workflow $workflow, ?array $distributionUserIds = null): Collection
    {
        $query = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'appointment_setter')
            ->orderBy('users.id');

        $selectedUserIds = $distributionUserIds ?? $workflow?->distribution_users;
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

    public function assignLeadsToSetter(Workspace $workspace, User $setter, int $count, User $actor): int
    {
        if ($count < 1 || ! $setter->isAppointmentSetter($workspace->id)) {
            return 0;
        }

        $cap = (int) config('sales_ops.leads_per_setter', 500);
        $availableSlots = max(0, $cap - $this->setterActiveLoad($workspace, $setter->id));
        $toAssign = min($count, $availableSlots, $this->unassignedLeadCount($workspace));

        if ($toAssign === 0) {
            return 0;
        }

        $leads = $this->unassignedLeadQuery($workspace)
            ->orderBy('row_number')
            ->limit($toAssign)
            ->get();

        $assigned = 0;
        foreach ($leads as $lead) {
            if ($this->assignToSetter($workspace, $lead, $setter, $actor)) {
                $assigned++;
            }
        }

        return $assigned;
    }

    public function assignWorkflowLeadsToTeamLead(
        Workspace $workspace,
        Workflow $workflow,
        User $teamLead,
        int $count,
        User $actor,
    ): int {
        if ($count < 1 || $teamLead->getWorkspaceRole($workspace->id) !== WorkflowAssignmentRoles::setterTeamLeadRole()) {
            return 0;
        }

        $setters = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();

        if ($setters->isEmpty()) {
            return 0;
        }

        $toAssign = min($count, $this->unassignedWorkflowLeadCount($workflow));
        if ($toAssign === 0) {
            return 0;
        }

        $leads = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
            ->orderBy('row_number')
            ->limit($toAssign)
            ->get();

        $assigned = 0;
        foreach ($leads as $lead) {
            $setter = $this->leastLoadedSetter($workspace, $setters);
            if ($setter && $this->assignToSetter($workspace, $lead, $setter, $actor)) {
                $assigned++;
            }
        }

        return $assigned;
    }

    public function unassignedLeadCount(Workspace $workspace): int
    {
        return $this->unassignedLeadQuery($workspace)->count();
    }

    public function unassignedWorkflowLeadCount(Workflow $workflow): int
    {
        return WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
            ->count();
    }

    public function setterActiveLoad(Workspace $workspace, int $setterId): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_user_id', $setterId)
            ->where('pipeline_phase', 'with_setter')
            ->count();
    }

    public function needsRebalance(Workspace $workspace, ?array $distributionUserIds = null): bool
    {
        $users = $this->resolvePool($workspace, null, $distributionUserIds);
        if ($users->count() < 2) {
            return false;
        }

        $loads = $users->map(fn (User $user) => $this->setterActiveLoad($workspace, $user->id));

        return ($loads->max() - $loads->min()) > 1;
    }

    public function rebalanceWorkspace(Workspace $workspace, User $actor, ?array $distributionUserIds = null): int
    {
        $setters = $this->resolvePool($workspace, null, $distributionUserIds);
        if ($setters->count() < 2) {
            return 0;
        }

        $moved = 0;
        $maxPasses = 500;

        while ($maxPasses-- > 0 && $this->needsRebalance($workspace, $distributionUserIds)) {
            $loads = $setters->mapWithKeys(
                fn (User $user) => [$user->id => $this->setterActiveLoad($workspace, $user->id)]
            );

            $fromId = $loads->sortDesc()->keys()->first();
            $toId = $loads->sort()->keys()->first();

            if ($loads[$fromId] - $loads[$toId] <= 1) {
                break;
            }

            $lead = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_user_id', $fromId)
                ->where('pipeline_phase', 'with_setter')
                ->where('setter_status', 'new')
                ->orderBy('updated_at')
                ->first();

            if (! $lead) {
                break;
            }

            $toSetter = $setters->firstWhere('id', $toId);
            if (! $toSetter) {
                break;
            }

            $lead->update([
                'assigned_user_id' => $toSetter->id,
                'assigned_setter_id' => $toSetter->id,
            ]);

            $this->recordAssignment($lead, User::find($fromId), $toSetter, 'with_setter', $actor);
            $this->activities->logStatusChange(
                $lead->fresh(),
                $actor,
                'setter',
                'new',
                'new',
                "Lead rebalanced to {$toSetter->name}"
            );

            $moved++;
        }

        return $moved;
    }

    protected function unassignedLeadQuery(Workspace $workspace)
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id');
    }

    protected function leastLoadedSetter(Workspace $workspace, Collection $setters): ?User
    {
        if ($setters->isEmpty()) {
            return null;
        }

        $cap = (int) config('sales_ops.leads_per_setter', 500);

        return $setters
            ->sortBy(fn (User $setter) => $this->setterActiveLoad($workspace, $setter->id))
            ->first(fn (User $setter) => $this->setterActiveLoad($workspace, $setter->id) < $cap);
    }

    protected function assignToSetter(Workspace $workspace, WorkflowLead $lead, User $setter, User $actor): bool
    {
        return (bool) SqliteConcurrency::retry(function () use ($workspace, $lead, $setter, $actor) {
            return DB::transaction(function () use ($workspace, $lead, $setter, $actor) {
                $locked = WorkflowLead::lockForUpdate()->find($lead->id);
                if (
                    ! $locked
                    || $locked->assigned_user_id
                    || $locked->pipeline_phase !== 'enriched'
                    || $locked->status !== 'enriched'
                ) {
                    return false;
                }

                $locked->update([
                    'assigned_user_id' => $setter->id,
                    'assigned_setter_id' => $setter->id,
                    'pipeline_phase' => 'with_setter',
                    'setter_status' => 'new',
                    'status' => 'completed',
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                ]);

                $this->recordAssignment($locked, null, $setter, 'with_setter', $actor);
                $this->activities->logStatusChange(
                    $locked->fresh(),
                    $actor,
                    'setter',
                    null,
                    'new',
                    "Lead assigned to {$setter->name} by team lead"
                );

                $workflow = Workflow::lockForUpdate()->find($locked->workflow_id);
                if ($workflow) {
                    $workflow->increment('processed_leads');
                }

                return true;
            });
        });
    }
}
