<?php

namespace App\Services\Workspace;

use App\Models\LeadActivity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSyncEvent;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Services\SalesOps\SdrPerformanceService;
use App\Support\SalesOps;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WorkspaceSyncService
{
    public function __construct(
        protected SdrPerformanceService $performance,
    ) {}

    public function record(
        Workspace $workspace,
        string $eventType,
        string $entityType,
        ?int $entityId = null,
        array $payload = [],
        ?int $actorId = null,
    ): WorkspaceSyncEvent {
        return WorkspaceSyncEvent::create([
            'workspace_id' => $workspace->id,
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => $payload,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }

    public function poll(
        Workspace $workspace,
        User $user,
        ?string $version = null,
        ?int $cursor = null,
        ?int $workflowId = null,
        ?int $leadId = null,
    ): array {
        $fingerprint = $this->fingerprint($workspace, $user, $workflowId, $leadId);
        $latestCursor = (int) (WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0);

        $events = [];
        if ($cursor !== null) {
            $events = WorkspaceSyncEvent::where('workspace_id', $workspace->id)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit(50)
                ->get()
                ->map(fn (WorkspaceSyncEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id,
                    'payload' => $event->payload ?? [],
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        if ($version !== null && $version === $fingerprint && empty($events)) {
            return [
                'changed' => false,
                'version' => $fingerprint,
                'cursor' => $latestCursor,
                'events' => [],
            ];
        }

        $state = $this->buildState($workspace, $user, $cursor, $workflowId, $leadId, $fingerprint, $latestCursor, $events);

        return [
            'changed' => $version === null || $state['version'] !== $version || ! empty($events),
            ...$state,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $events
     * @return array<string, mixed>
     */
    protected function buildState(
        Workspace $workspace,
        User $user,
        ?int $cursor,
        ?int $workflowId,
        ?int $leadId,
        string $fingerprint,
        int $latestCursor,
        ?array $events = null,
    ): array {
        $isAdmin = $user->isWorkspaceAdmin($workspace->id);
        $workflowIds = $this->workflowIds($workspace);

        if ($events === null) {
            $events = WorkspaceSyncEvent::where('workspace_id', $workspace->id)
                ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
                ->orderBy('id')
                ->limit(50)
                ->get()
                ->map(fn (WorkspaceSyncEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'entity_type' => $event->entity_type,
                    'entity_id' => $event->entity_id,
                    'payload' => $event->payload ?? [],
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        $workflows = $this->loadWorkflows($workspace, $workflowId);
        $leads = $this->loadLeads($workflowIds, $user, $isAdmin, $workflowId);
        $team = $workspace->users()->get(['users.id', 'users.name', 'users.email']);

        $state = [
            'version' => $fingerprint,
            'cursor' => $latestCursor,
            'events' => $events,
            'workflows' => $workflows->map(fn (Workflow $wf) => $this->serializeWorkflow($wf))->values()->all(),
            'leads' => $leads->map(fn (WorkflowLead $lead) => $this->serializeLead($lead))->values()->all(),
            'team' => $team->map(fn (User $member) => $this->serializeTeamMember($member, $workspace, $user))->values()->all(),
            'sales_ops' => $this->buildSalesOpsPayload($workspace, $user, $isAdmin),
        ];

        if ($leadId) {
            $state['lead_detail'] = $this->buildLeadDetailPayload($leadId, $workflowIds);
        }

        if ($isAdmin) {
            $state['workspace_context'] = $this->serializeWorkspaceContext($workspace);
            $state['workspaces'] = $this->serializeWorkspacesList($user, $workspace);
        }

        return $state;
    }

    protected function fingerprint(Workspace $workspace, User $user, ?int $workflowId, ?int $leadId): string
    {
        $workflowIds = $this->workflowIds($workspace);

        $leadStamp = $workflowIds->isEmpty()
            ? ''
            : (WorkflowLead::whereIn('workflow_id', $workflowIds)->max('updated_at') ?? '');

        $workflowStamp = Workflow::where('workspace_id', $workspace->id)
            ->when($workflowId, fn ($q) => $q->where('id', $workflowId))
            ->max('updated_at') ?? '';

        $teamStamp = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->max('updated_at');

        $eventStamp = WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0;

        $activityStamp = $workflowIds->isEmpty()
            ? 0
            : (LeadActivity::whereIn('workflow_lead_id', function ($query) use ($workflowIds) {
                $query->select('id')->from('workflow_leads')->whereIn('workflow_id', $workflowIds);
            })->max('id') ?? 0);

        $leadDetailStamp = $leadId
            ? (WorkflowLead::whereIn('workflow_id', $workflowIds)->where('id', $leadId)->value('updated_at') ?? '')
            : '';

        return md5(implode('|', [
            $workspace->id,
            $user->id,
            $workflowId ?? '',
            $leadId ?? '',
            $leadStamp,
            $workflowStamp,
            $teamStamp,
            $eventStamp,
            $activityStamp,
            $leadDetailStamp,
            $workspace->updated_at,
        ]));
    }

    /**
     * @return Collection<int, int>
     */
    protected function workflowIds(Workspace $workspace): Collection
    {
        return $workspace->workflows()->pluck('id');
    }

    /**
     * @return Collection<int, Workflow>
     */
    protected function loadWorkflows(Workspace $workspace, ?int $workflowId): Collection
    {
        return $workspace->workflows()
            ->when($workflowId, fn ($q) => $q->where('id', $workflowId))
            ->latest()
            ->withCount([
                'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                'leads as pending_verification_count' => fn ($query) => $query->where('status', 'pending_verification'),
            ])
            ->limit($workflowId ? 1 : 12)
            ->get();
    }

    /**
     * @param  Collection<int, int>  $workflowIds
     * @return Collection<int, WorkflowLead>
     */
    protected function loadLeads(Collection $workflowIds, User $user, bool $isAdmin, ?int $workflowId): Collection
    {
        if ($workflowIds->isEmpty()) {
            return collect();
        }

        $query = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->with('assignee:id,name');

        if ($workflowId) {
            $query->where('workflow_id', $workflowId);
        } elseif (! $isAdmin) {
            $query->where('assigned_user_id', $user->id)
                ->where('status', 'completed');

            if ($user->isAccountExecutive()) {
                $query->whereIn('stage', ['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost']);
            }
        }

        return $query
            ->orderBy('tier')
            ->orderByRaw("CASE WHEN status = 'pending_verification' THEN 0 WHEN status = 'extracting' THEN 1 WHEN status = 'completed' THEN 2 WHEN status = 'failed' THEN 3 ELSE 4 END ASC")
            ->orderByDesc('updated_at')
            ->limit($workflowId ? 50 : 100)
            ->get();
    }

    /**
     * @param  Collection<int, int>  $workflowIds
     * @return array<string, mixed>|null
     */
    protected function buildLeadDetailPayload(int $leadId, Collection $workflowIds): ?array
    {
        if ($workflowIds->isEmpty()) {
            return null;
        }

        $lead = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->where('id', $leadId)
            ->first();

        if (! $lead) {
            return null;
        }

        $activities = LeadActivity::query()
            ->where('workflow_lead_id', $lead->id)
            ->with('user:id,name')
            ->latest()
            ->limit(12)
            ->get();

        $activityTypes = config('sales_ops.activity_types', []);

        return [
            'lead' => $this->serializeLead($lead),
            'activities' => $activities->map(fn (LeadActivity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'type_label' => $activityTypes[$activity->type] ?? $activity->type,
                'outcome' => $activity->outcome,
                'notes' => $activity->notes,
                'user_name' => $activity->user?->name,
                'created_at' => $activity->created_at?->diffForHumans(),
            ])->values()->all(),
            'contact_attempts' => $lead->contact_attempts,
            'tier' => $lead->tier,
            'tier_label' => SalesOps::tierLabel($lead->tier),
            'stage' => $lead->stage,
            'stage_label' => SalesOps::crmStageLabel($lead->stage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSalesOpsPayload(Workspace $workspace, User $user, bool $isAdmin): array
    {
        $payload = [];

        if ($isAdmin) {
            $overview = $this->performance->workspaceOverview($workspace);
            $payload['overview'] = $overview;
            $payload['leaderboard'] = $this->performance->teamLeaderboard($workspace, 'week')->take(15)->values()->all();
            $payload['sdr_load'] = $this->performance->sdrLoad($workspace)->values()->all();
        }

        if ($user->isSdr() || $user->isMarketerOnly() || $user->isAccountExecutive()) {
            $payload['daily'] = $this->performance->dailyMetrics($user, $workspace);
            $payload['weekly'] = $this->performance->weeklyMetrics($user, $workspace);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTeamMember(User $member, Workspace $workspace, User $viewer): array
    {
        $isOwner = $workspace->admin_id === $member->id;

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->pivot->role,
            'status' => $member->pivot->status ?? 'active',
            'is_owner' => $isOwner,
            'can_manage' => $viewer->isWorkspaceAdmin($workspace->id) && ! $isOwner,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeWorkspaceContext(Workspace $workspace): array
    {
        $workspace->loadMissing('admin:id,name,email');
        $workspace->loadCount(['workflows', 'users']);

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'admin_name' => $workspace->admin?->name,
            'admin_email' => $workspace->admin?->email,
            'workflow_count' => (int) $workspace->workflows_count,
            'member_count' => (int) $workspace->users_count,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function serializeWorkspacesList(User $user, Workspace $activeWorkspace): array
    {
        return $user->switchableWorkspaces()
            ->map(function (Workspace $workspace) use ($activeWorkspace) {
                $workspace->loadMissing('admin:id,name');
                $workspace->loadCount(['workflows', 'users']);

                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'admin_name' => $workspace->admin?->name,
                    'is_active' => $workspace->id === $activeWorkspace->id,
                    'workflow_count' => (int) $workspace->workflows_count,
                    'member_count' => (int) $workspace->users_count,
                ];
            })
            ->values()
            ->all();
    }

    protected function serializeWorkflow(Workflow $workflow): array
    {
        $enriched = ($workflow->processed_leads ?? 0) + ($workflow->pending_verification_count ?? 0);

        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'original_filename' => $workflow->original_filename,
            'total_leads' => $workflow->total_leads,
            'processed_leads' => $workflow->processed_leads,
            'failed_leads' => $workflow->failed_leads,
            'enriched_leads' => $enriched,
            'assigned_leads' => (int) ($workflow->assigned_leads_count ?? 0),
            'pending_verification' => (int) ($workflow->pending_verification_count ?? 0),
            'completion_pct' => $workflow->total_leads > 0
                ? (int) round((($workflow->processed_leads + $workflow->failed_leads) / $workflow->total_leads) * 100)
                : 0,
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    protected function serializeLead(WorkflowLead $lead): array
    {
        return [
            'id' => $lead->id,
            'workflow_id' => $lead->workflow_id,
            'row_number' => $lead->row_number,
            'assigned_user_id' => $lead->assigned_user_id,
            'assignee_name' => $lead->assignee?->name,
            'status' => $lead->status,
            'verification_status' => $lead->verification_status,
            'business_name' => $lead->business_name,
            'address' => $lead->address,
            'city' => $lead->city,
            'state' => $lead->state,
            'owner_name' => $lead->owner_name,
            'direct_email' => $lead->direct_email,
            'direct_phone' => $lead->direct_phone,
            'payment_processor' => $lead->payment_processor,
            'stage' => $lead->stage,
            'stage_label' => SalesOps::crmStageLabel($lead->stage),
            'tier' => $lead->tier,
            'tier_label' => SalesOps::tierLabel($lead->tier),
            'contact_attempts' => (int) $lead->contact_attempts,
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
