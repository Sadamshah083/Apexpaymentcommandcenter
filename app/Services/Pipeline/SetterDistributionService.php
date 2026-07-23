<?php

namespace App\Services\Pipeline;

use App\Jobs\RebalanceSetterTeamJob;
use App\Models\LeadAssignment;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadActivityService;
use App\Support\LeadDialablePhone;
use App\Support\LeadStageSync;
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

                $leadModel->update(LeadStageSync::mergeStage($leadModel, [
                    'assigned_user_id' => $assignedUser->id,
                    'assigned_setter_id' => $assignedUser->id,
                    'pipeline_phase' => 'with_setter',
                    'setter_status' => 'new',
                    'status' => 'completed',
                ]));

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
            ->readyToAssign()
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

    /**
     * Assign specific unassigned leads to a setter (checkbox / selection flow).
     *
     * @param  array<int, int>  $leadIds
     */
    public function assignSelectedLeadsToSetter(Workspace $workspace, User $setter, array $leadIds, User $actor): int
    {
        if (! $setter->isAppointmentSetter($workspace->id)) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $leadIds))));
        if ($ids === []) {
            return 0;
        }

        $cap = (int) config('sales_ops.leads_per_setter', 500);
        $availableSlots = max(0, $cap - $this->setterActiveLoad($workspace, $setter->id));
        if ($availableSlots < 1) {
            return 0;
        }

        $leads = WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('id', $ids)
            ->readyToAssign()
            ->orderBy('row_number')
            ->limit($availableSlots)
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
            ->readyToAssign()
            ->count();

        $toAssign = min($count, $availableSlots, $poolSize);
        if ($toAssign === 0) {
            return 0;
        }

        $leads = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->readyToAssign()
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
        array $memberIds = [],
    ): int {
        $teamLeadRole = $teamLead->getWorkspaceRole($workspace->id);
        if ($count < 1 || ! WorkflowAssignmentRoles::isAssignableTeamLeadRole($teamLeadRole)) {
            return 0;
        }

        WorkflowLead::normalizeUnassignedForWorkflow($workflow->id);

        $agents = WorkflowAssignmentRoles::agentsForTeamLead($workspace, $teamLead);

        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if ($memberIds !== []) {
            $allowed = $agents->keyBy(fn ($user) => (int) $user->id);
            $selected = collect($memberIds)
                ->map(fn (int $id) => $allowed->get($id))
                ->filter()
                ->values();

            // Selected members must belong to this team (or workspace fallback pool).
            if ($selected->isEmpty()) {
                return 0;
            }
            $agents = $selected;
        }

        if ($agents->isEmpty()) {
            return 0;
        }

        $poolSize = $this->unassignedWorkflowLeadCount($workflow);
        $toAssign = min($count, $poolSize);
        if ($toAssign === 0) {
            return 0;
        }

        $leads = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->readyToAssign()
            ->orderBy('row_number')
            ->limit($toAssign)
            ->get();

        $assignToCloser = $teamLeadRole === WorkflowAssignmentRoles::closerTeamLeadRole();
        $assigned = 0;
        $agentCount = $agents->count();
        foreach ($leads as $index => $lead) {
            $agent = $agents[$index % $agentCount];
            $ok = $assignToCloser
                ? $this->assignToCloser($workspace, $lead, $agent, $actor, $teamLead)
                : $this->assignToSetter($workspace, $lead, $agent, $actor, $teamLead);
            if ($ok) {
                $assigned++;
            }
        }

        return $assigned;
    }

    public function assignCampaignLeadsToTeamLead(
        Workspace $workspace,
        int $campaignId,
        User $teamLead,
        int $count,
        User $actor,
        ?int $workflowId = null,
    ): int {
        if ($count < 1 || $teamLead->getWorkspaceRole($workspace->id) !== WorkflowAssignmentRoles::setterTeamLeadRole()) {
            return 0;
        }

        $setters = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();

        $teamSetters = $setters->filter(
            fn ($setter) => (int) ($setter->pivot->team_lead_user_id ?? 0) === (int) $teamLead->id
        )->values();

        if ($teamSetters->isNotEmpty()) {
            $setters = $teamSetters;
        }

        if ($setters->isEmpty()) {
            return 0;
        }

        $query = WorkflowLead::query()
            ->where('campaign_id', $campaignId)
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->readyToAssign();

        if ($workflowId) {
            $query->where('workflow_id', $workflowId);
        }

        $poolSize = (clone $query)->count();
        $toAssign = min($count, $poolSize);
        if ($toAssign === 0) {
            return 0;
        }

        $leads = $query->orderBy('row_number')->limit($toAssign)->get();

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
        WorkflowLead::normalizeUnassignedForWorkflow($workflow->id);

        return WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->readyToAssign()
            ->count();
    }

    public function unassignedLeadCount(Workspace $workspace): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->readyToAssign()
            ->count();
    }

    /**
     * Return assigned import leads to the unassigned pool so admins can assign them again.
     * Removes them from agent dialer/portal queues (clears assigned_user_id).
     *
     * @param  list<int>  $agentIds  Optional filter: only leads owned by these users.
     */
    public function unassignWorkflowLeadsToPool(
        Workspace $workspace,
        Workflow $workflow,
        int $count,
        User $actor,
        array $agentIds = [],
    ): int {
        if ($count < 1 || (int) $workflow->workspace_id !== (int) $workspace->id) {
            return 0;
        }

        $agentIds = collect($agentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $query = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->whereNotNull('assigned_user_id')
            ->whereIn('pipeline_phase', ['with_setter', 'with_closer'])
            ->orderByDesc('updated_at')
            ->limit($count);

        if ($agentIds !== []) {
            $query->whereIn('assigned_user_id', $agentIds);
        }

        $leads = $query->get();
        $unassigned = 0;

        foreach ($leads as $lead) {
            if ($this->unassignLeadToPool($lead, $actor)) {
                $unassigned++;
            }
        }

        return $unassigned;
    }

    public function assignedWorkflowLeadCount(Workflow $workflow, array $agentIds = []): int
    {
        $query = WorkflowLead::query()
            ->where('workflow_id', $workflow->id)
            ->whereNotNull('assigned_user_id')
            ->whereIn('pipeline_phase', ['with_setter', 'with_closer']);

        $agentIds = collect($agentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($agentIds !== []) {
            $query->whereIn('assigned_user_id', $agentIds);
        }

        return $query->count();
    }

    protected function unassignLeadToPool(WorkflowLead $lead, User $actor): bool
    {
        return (bool) SqliteConcurrency::retry(function () use ($lead, $actor) {
            return DB::transaction(function () use ($lead, $actor) {
                $locked = WorkflowLead::lockForUpdate()->find($lead->id);
                if (
                    ! $locked
                    || ! $locked->assigned_user_id
                    || ! in_array($locked->pipeline_phase, ['with_setter', 'with_closer'], true)
                ) {
                    return false;
                }

                $from = User::find($locked->assigned_user_id);
                $wasEnriched = filled($locked->researched_at);
                $isStoredImport = ($locked->import_mode === 'stored');

                // pipeline_phase is NOT NULL in DB — never write null.
                $poolStatus = $wasEnriched ? 'enriched' : ($isStoredImport ? 'imported' : 'enriched');
                $poolPhase = $wasEnriched ? 'enriched' : ($isStoredImport ? 'imported' : 'enriched');

                $locked->update(LeadStageSync::mergeStage($locked, [
                    'assigned_user_id' => null,
                    'assigned_setter_id' => null,
                    'assigned_closer_id' => null,
                    'setter_status' => null,
                    'closer_status' => null,
                    'pipeline_phase' => $poolPhase,
                    'status' => $poolStatus,
                ]));

                $this->recordAssignment($locked, $from, null, $poolPhase, $actor);

                try {
                    $this->activities->log(
                        $locked->fresh(),
                        $actor,
                        'note',
                        'unassigned',
                        $from
                            ? "Lead unassigned from {$from->name} and returned to the assign pool"
                            : 'Lead returned to the assign pool'
                    );
                } catch (\Throwable $e) {
                    // Activity log must not block returning leads to the pool.
                    report($e);
                }

                $workflow = Workflow::lockForUpdate()->find($locked->workflow_id);
                if ($workflow && (int) $workflow->processed_leads > 0) {
                    $workflow->decrement('processed_leads');
                }

                return true;
            });
        });
    }

    protected function assignToSetter(Workspace $workspace, WorkflowLead $lead, User $setter, User $actor, ?User $teamLead = null): bool
    {
        return (bool) SqliteConcurrency::retry(function () use ($workspace, $lead, $setter, $actor, $teamLead) {
            return DB::transaction(function () use ($workspace, $lead, $setter, $actor, $teamLead) {
                $locked = WorkflowLead::lockForUpdate()->find($lead->id);
                if (
                    ! $locked
                    || ! WorkflowLead::query()->whereKey($locked->id)->readyToAssign()->exists()
                ) {
                    return false;
                }

                $phoneUpdates = LeadDialablePhone::syncAttributes($locked);
                $campaignId = $this->resolveAssignmentCampaignId($locked, $setter, $teamLead);

                $locked->update(LeadStageSync::mergeStage($locked, array_merge($phoneUpdates, array_filter([
                    'assigned_user_id' => $setter->id,
                    'assigned_setter_id' => $setter->id,
                    'pipeline_phase' => 'with_setter',
                    'setter_status' => 'new',
                    'status' => 'completed',
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                    'campaign_id' => $campaignId,
                ], fn ($value) => $value !== null))));

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

    /**
     * Assign an enriched import lead directly to a closer under a Closers Team Lead.
     */
    protected function assignToCloser(Workspace $workspace, WorkflowLead $lead, User $closer, User $actor, ?User $teamLead = null): bool
    {
        return (bool) SqliteConcurrency::retry(function () use ($workspace, $lead, $closer, $actor, $teamLead) {
            return DB::transaction(function () use ($workspace, $lead, $closer, $actor, $teamLead) {
                $locked = WorkflowLead::lockForUpdate()->find($lead->id);
                if (
                    ! $locked
                    || ! WorkflowLead::query()->whereKey($locked->id)->readyToAssign()->exists()
                ) {
                    return false;
                }

                $phoneUpdates = LeadDialablePhone::syncAttributes($locked);
                $campaignId = $this->resolveAssignmentCampaignId($locked, $closer, $teamLead);

                $locked->update(LeadStageSync::mergeStage($locked, array_merge($phoneUpdates, array_filter([
                    'assigned_user_id' => $closer->id,
                    'assigned_closer_id' => $closer->id,
                    'pipeline_phase' => 'with_closer',
                    'closer_status' => 'new',
                    'status' => 'completed',
                    'verification_status' => 'approved',
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                    'campaign_id' => $campaignId,
                ], fn ($value) => $value !== null))));

                $this->recordAssignment($locked, null, $closer, 'with_closer', $actor);
                $note = $teamLead
                    ? "Lead assigned to closer {$closer->name} under team lead {$teamLead->name}"
                    : "Lead assigned to closer {$closer->name}";
                $this->activities->logStatusChange(
                    $locked->fresh(),
                    $actor,
                    'closer',
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

    protected function resolveAssignmentCampaignId(WorkflowLead $lead, User $agent, ?User $teamLead): ?int
    {
        if ((int) ($lead->campaign_id ?? 0) > 0) {
            return (int) $lead->campaign_id;
        }

        $fromLead = (int) ($teamLead?->pivot?->campaign_id ?? 0);
        if ($fromLead > 0) {
            return $fromLead;
        }

        $fromAgent = (int) ($agent->pivot->campaign_id ?? 0);

        return $fromAgent > 0 ? $fromAgent : null;
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
            ->readyToAssign()
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
