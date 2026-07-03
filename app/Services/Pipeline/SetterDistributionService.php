<?php

namespace App\Services\Pipeline;

use App\Jobs\RebalanceSetterTeamJob;
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

                $cursor = (int) ($workflowModel->distribution_cursor ?? 0);
                $cap = (int) config('sales_ops.leads_per_setter', 500);
                $assignedUser = $this->pickSetterForAssignment($workspace, $users, $cursor, $cap);

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

    public function assignLeadsToSetter(Workspace $workspace, User $setter, int $count, User $actor): int
    {
        if ($count < 1) {
            return 0;
        }

        if (! $setter->isAppointmentSetter($workspace->id)) {
            return 0;
        }

        $cap = (int) config('sales_ops.leads_per_setter', 500);
        $currentLoad = $this->setterActiveLoad($workspace, $setter->id);
        $availableSlots = max(0, $cap - $currentLoad);
        $toAssign = min($count, $availableSlots, $this->unassignedLeadCount($workspace));

        if ($toAssign === 0) {
            return 0;
        }

        $leads = WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
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

    public function assignWorkflowLeadsToSetter(
        Workspace $workspace,
        Workflow $workflow,
        User $setter,
        int $count,
        User $actor,
        ?User $teamLead = null,
    ): int {
        if ($count < 1 || ! $setter->isAppointmentSetter($workspace->id)) {
            return 0;
        }

        $cap = (int) config('sales_ops.leads_per_setter', 500);
        $availableSlots = max(0, $cap - $this->setterActiveLoad($workspace, $setter->id));
        $poolSize = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
            ->count();

        $toAssign = min($count, $availableSlots, $poolSize);
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
            if ($this->assignToSetter($workspace, $lead, $setter, $actor, $teamLead)) {
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

        $poolSize = $this->unassignedWorkflowLeadCount($workflow);
        $toAssign = min($count, $poolSize);
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
        $setterCount = $setters->count();
        foreach ($leads as $index => $lead) {
            $setter = $setters[$index % $setterCount];
            if ($this->assignToSetter($workspace, $lead, $setter, $actor, $teamLead)) {
                $assigned++;
            }
        }

        return $assigned;
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

    public function unassignedLeadCount(Workspace $workspace): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
            ->count();
    }

    protected function assignToSetter(Workspace $workspace, WorkflowLead $lead, User $setter, User $actor, ?User $teamLead = null): bool
    {
        return (bool) SqliteConcurrency::retry(function () use ($workspace, $lead, $setter, $actor, $teamLead) {
            return DB::transaction(function () use ($workspace, $lead, $setter, $actor, $teamLead) {
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
                $note = $teamLead
                    ? "Lead assigned to {$setter->name} under team lead {$teamLead->name}"
                    : "Lead assigned to {$setter->name} by team lead";
                $this->activities->logStatusChange(
                    $locked->fresh(),
                    $actor,
                    'setter',
                    null,
                    'new',
                    $note
                );

                $workflow = Workflow::lockForUpdate()->find($locked->workflow_id);
                if ($workflow) {
                    $workflow->increment('processed_leads');
                    $this->maybeCompleteWorkflowDistribution($workflow->fresh());
                }

                return true;
            });
        });
    }

    public function rebalanceWorkspace(Workspace $workspace, User $actor, ?array $distributionUserIds = null): int
    {
        $users = $this->resolvePool($workspace, null, $distributionUserIds);
        if ($users->count() < 2) {
            return 0;
        }

        $moved = 0;
        $maxIterations = 5000;

        for ($iteration = 0; $iteration < $maxIterations; $iteration++) {
            $loads = $users->mapWithKeys(fn (User $user) => [
                $user->id => $this->setterActiveLoad($workspace, $user->id),
            ]);

            $maxLoad = $loads->max();
            $minLoad = $loads->min();

            if ($maxLoad - $minLoad <= 1) {
                break;
            }

            $fromId = $loads->filter(fn (int $load) => $load === $maxLoad)->keys()->first();
            $toId = $loads->filter(fn (int $load) => $load === $minLoad)->keys()->first();

            $lead = WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                ->where('assigned_user_id', $fromId)
                ->where('pipeline_phase', 'with_setter')
                ->where('setter_status', 'new')
                ->orderByDesc('updated_at')
                ->first();

            if (! $lead) {
                break;
            }

            $didMove = SqliteConcurrency::retry(function () use ($workspace, $lead, $fromId, $toId, $actor) {
                return DB::transaction(function () use ($workspace, $lead, $fromId, $toId, $actor) {
                    $locked = WorkflowLead::lockForUpdate()->find($lead->id);
                    if (
                        ! $locked
                        || $locked->pipeline_phase !== 'with_setter'
                        || (int) $locked->assigned_user_id !== (int) $fromId
                        || $locked->setter_status !== 'new'
                    ) {
                        return false;
                    }

                    $from = User::find($fromId);
                    $to = User::find($toId);
                    if (! $from || ! $to) {
                        return false;
                    }

                    $locked->update([
                        'assigned_user_id' => $to->id,
                        'assigned_setter_id' => $to->id,
                    ]);

                    $this->recordAssignment($locked, $from, $to, 'with_setter', $actor);
                    $this->activities->log(
                        $locked->fresh(),
                        $actor,
                        'note',
                        'rebalanced',
                        "Lead rebalanced from {$from->name} to {$to->name}"
                    );

                    return true;
                });
            });

            if (! $didMove) {
                break;
            }

            $moved++;
        }

        return $moved;
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

    public function queueRebalanceIfNeeded(Workspace $workspace): void
    {
        if ($this->needsRebalance($workspace)) {
            RebalanceSetterTeamJob::dispatch($workspace->id);
        }
    }

    protected function pickSetterForAssignment(
        Workspace $workspace,
        Collection $users,
        int $cursor,
        int $cap,
    ): ?User {
        $userCount = $users->count();
        if ($userCount === 0) {
            return null;
        }

        $candidate = $users
            ->values()
            ->map(fn (User $user, int $index) => [
                'user' => $user,
                'load' => $this->setterActiveLoad($workspace, $user->id),
                'order' => ($index - ($cursor % $userCount) + $userCount) % $userCount,
            ])
            ->filter(fn (array $row) => $row['load'] < $cap)
            ->sortBy([
                ['load', 'asc'],
                ['order', 'asc'],
            ])
            ->first();

        return $candidate['user'] ?? null;
    }

    protected function setterActiveLoad(Workspace $workspace, int $userId): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_user_id', $userId)
            ->where('pipeline_phase', 'with_setter')
            ->count();
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

    protected function maybeCompleteWorkflowDistribution(Workflow $workflow): void
    {
        if ($workflow->total_leads < 1) {
            return;
        }

        $remaining = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->where('status', 'enriched')
            ->where('pipeline_phase', 'enriched')
            ->whereNull('assigned_user_id')
            ->count();

        if ($remaining === 0) {
            $workflow->update(['status' => 'completed']);
        }
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
