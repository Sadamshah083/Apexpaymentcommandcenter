<?php

namespace App\Services\Workspace;

use App\Models\ContentAnalysis;
use App\Models\EmailList;
use App\Models\LeadActivity;
use App\Models\ReputationLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSyncEvent;
use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\DeliverabilityTest;
use App\Services\SalesOps\SdrPerformanceService;
use App\Support\PipelineProgress;
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
        string $scope = 'full',
    ): array {
        if ($scope === 'off') {
            $latestCursor = (int) (WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0);

            return [
                'changed' => false,
                'version' => $version ?? '',
                'cursor' => $latestCursor,
                'events' => [],
            ];
        }

        $lite = $scope === 'lite';
        $list = $scope === 'list';
        $fingerprint = $lite
            ? $this->fingerprintLite($workspace, $user)
            : $this->fingerprint($workspace, $user, $workflowId, $leadId, $list ? 'list' : null);
        $latestCursor = (int) (WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0);

        $events = [];
        if ($cursor !== null) {
            $events = WorkspaceSyncEvent::where('workspace_id', $workspace->id)
                ->where('id', '>', $cursor)
                ->orderBy('id')
                ->limit($lite ? 10 : ($list ? 20 : 50))
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

        $state = $lite
            ? $this->buildLiteState($workspace, $user, $fingerprint, $latestCursor, $events)
            : $this->buildState($workspace, $user, $cursor, $workflowId, $leadId, $fingerprint, $latestCursor, $events, $scope);

        return [
            'changed' => $version === null || $state['version'] !== $version || ! empty($events),
            ...$state,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $events
     * @return array<string, mixed>
     */
    protected function buildLiteState(
        Workspace $workspace,
        User $user,
        string $fingerprint,
        int $latestCursor,
        ?array $events = null,
    ): array {
        return [
            'version' => $fingerprint,
            'cursor' => $latestCursor,
            'events' => $events ?? [],
            'workflows' => [],
            'leads' => [],
            'team' => [],
            'sales_ops' => [],
            'toolkit' => $this->buildToolkitPayload($workspace, $user),
        ];
    }

    protected function fingerprintLite(Workspace $workspace, User $user): string
    {
        $listStamp = EmailList::where('workspace_id', $workspace->id)->max('updated_at') ?? '';
        $deliverabilityStamp = DeliverabilityTest::where('workspace_id', $workspace->id)->max('updated_at') ?? '';
        $contentStamp = ContentAnalysis::where('workspace_id', $workspace->id)->max('updated_at') ?? '';
        $reputationStamp = ReputationLog::where('workspace_id', $workspace->id)->max('recorded_at') ?? '';
        $eventStamp = WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0;

        return md5(implode('|', [
            'lite',
            $workspace->id,
            $user->id,
            $listStamp,
            $deliverabilityStamp,
            $contentStamp,
            $reputationStamp,
            $eventStamp,
            $workspace->updated_at,
        ]));
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
        string $scope = 'full',
    ): array {
        $isAdmin = $user->isWorkspaceAdmin($workspace->id);
        $workflowIds = $this->workflowIds($workspace);
        $isListScope = $scope === 'list';

        if ($events === null) {
            $events = WorkspaceSyncEvent::where('workspace_id', $workspace->id)
                ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
                ->orderBy('id')
                ->limit($isListScope ? 20 : 50)
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

        $workflows = $this->loadWorkflows($workspace, $workflowId, $isListScope);
        $leads = $isListScope
            ? collect()
            : $this->loadLeads($workspace, $workflowIds, $user, $isAdmin, $workflowId);

        $state = [
            'version' => $fingerprint,
            'cursor' => $latestCursor,
            'events' => $events,
            'workflows' => $workflows->map(fn (Workflow $wf) => $this->serializeWorkflow($wf, $isListScope))->values()->all(),
            'leads' => $leads->map(fn (WorkflowLead $lead) => $this->serializeLead($lead))->values()->all(),
        ];

        if ($isListScope || ($workflowId && ! $leadId)) {
            return $state;
        }

        $team = $workspace->users()->get(['users.id', 'users.name', 'users.email']);

        $state['team'] = $team->map(fn (User $member) => $this->serializeTeamMember($member, $workspace, $user))->values()->all();
        $state['sales_ops'] = $this->buildSalesOpsPayload($workspace, $user, $isAdmin);
        $state['toolkit'] = $this->buildToolkitPayload($workspace, $user);

        if ($leadId) {
            $state['lead_detail'] = $this->buildLeadDetailPayload($leadId, $workflowIds);
        }

        if ($isAdmin) {
            $state['workspace_context'] = $this->serializeWorkspaceContext($workspace);
            $state['workspaces'] = $this->serializeWorkspacesList($user, $workspace);
        }

        return $state;
    }

    protected function fingerprint(Workspace $workspace, User $user, ?int $workflowId, ?int $leadId, ?string $syncScope = null): string
    {
        $workflowIds = $this->workflowIds($workspace);

        $workflowStamp = Workflow::where('workspace_id', $workspace->id)
            ->when($workflowId, fn ($q) => $q->where('id', $workflowId))
            ->max('updated_at') ?? '';

        $eventStamp = WorkspaceSyncEvent::where('workspace_id', $workspace->id)->max('id') ?? 0;

        if ($syncScope === 'list') {
            return md5(implode('|', [
                $workspace->id,
                $workflowStamp,
                $eventStamp,
            ]));
        }

        $leadStamp = $workflowIds->isEmpty()
            ? ''
            : (WorkflowLead::whereIn('workflow_id', $workflowIds)->max('updated_at') ?? '');

        $teamStamp = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->max('updated_at');

        $activityStamp = $workflowIds->isEmpty()
            ? 0
            : (LeadActivity::whereIn('workflow_lead_id', function ($query) use ($workflowIds) {
                $query->select('id')->from('workflow_leads')->whereIn('workflow_id', $workflowIds);
            })->max('id') ?? 0);

        $leadDetailStamp = $leadId
            ? (WorkflowLead::whereIn('workflow_id', $workflowIds)->where('id', $leadId)->value('updated_at') ?? '')
            : '';

        $listStamp = EmailList::where('workspace_id', $workspace->id)->max('updated_at') ?? '';
        $deliverabilityStamp = DeliverabilityTest::where('workspace_id', $workspace->id)->max('updated_at') ?? '';

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
            $listStamp,
            $deliverabilityStamp,
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
    protected function loadWorkflows(Workspace $workspace, ?int $workflowId, bool $light = false): Collection
    {
        $query = $workspace->workflows()
            ->when($workflowId, fn ($q) => $q->where('id', $workflowId))
            ->with('leadList:id,name')
            ->latest();

        if ($light) {
            return $query
                ->select([
                    'id',
                    'workspace_id',
                    'name',
                    'original_filename',
                    'status',
                    'total_leads',
                    'processed_leads',
                    'enriched_leads',
                    'failed_leads',
                    'import_tag_ids',
                    'lead_list_id',
                    'updated_at',
                ])
                ->withCount([
                    'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                    'leads as ready_to_assign_count' => fn ($query) => $query
                        ->where('status', 'enriched')
                        ->whereNull('assigned_user_id'),
                ])
                ->limit((int) config('pagination.workflows_per_page', 8))
                ->get();
        }

        return $query
            ->withCount([
                'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                'leads as ready_to_assign_count' => fn ($query) => $query
                    ->where('status', 'enriched')
                    ->whereNull('assigned_user_id'),
                'leads as pending_verification_count' => fn ($query) => $query->where('status', 'pending_verification'),
                'leads as imported_leads_count' => fn ($query) => $query->where('status', 'imported'),
                'leads as extracting_leads_count' => fn ($query) => $query->where('status', 'extracting'),
            ])
            ->limit($workflowId ? 1 : 12)
            ->get();
    }

    /**
     * @param  Collection<int, int>  $workflowIds
     * @return Collection<int, WorkflowLead>
     */
    protected function loadLeads(Workspace $workspace, Collection $workflowIds, User $user, bool $isAdmin, ?int $workflowId): Collection
    {
        if ($workflowIds->isEmpty()) {
            return collect();
        }

        $query = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->with(['assignee:id,name', 'tags', 'leadList']);

        if ($workflowId) {
            $query->where('workflow_id', $workflowId);
        } elseif (! $isAdmin) {
            $role = $user->getWorkspaceRole($workspace->id);

            $query->where(function ($q) use ($user, $role) {
                if ($role === 'appointment_setter') {
                    $q->where('pipeline_phase', 'with_setter')
                        ->where('assigned_user_id', $user->id);
                } elseif ($role === 'appointment_setter_team_lead') {
                    $q->whereIn('pipeline_phase', ['with_setter', 'appointment_settled', 'with_closer', 'closed'])
                        ->whereNotNull('assigned_setter_id');
                } elseif ($role === 'closers_team_lead') {
                    $q->whereIn('pipeline_phase', ['appointment_settled', 'with_closer', 'closed']);
                } elseif ($role === 'closer') {
                    $q->where('pipeline_phase', 'with_closer')
                        ->where('assigned_user_id', $user->id);
                } else {
                    $q->whereRaw('1 = 0');
                }
            });
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
            ->with(['tags', 'leadList'])
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
            $workflowIds = $this->workflowIds($workspace);
            $payload['pipeline_overview'] = WorkflowLead::query()
                ->whereIn('workflow_id', $workflowIds)
                ->selectRaw('pipeline_phase, count(*) as total')
                ->groupBy('pipeline_phase')
                ->pluck('total', 'pipeline_phase');
        }

        if ($user->isAppointmentSetter($workspace->id) || $user->isCloser($workspace->id)) {
            $payload['pipeline'] = [
                'active_leads' => WorkflowLead::query()
                    ->whereIn('workflow_id', $this->workflowIds($workspace))
                    ->where('assigned_user_id', $user->id)
                    ->whereIn('pipeline_phase', ['with_setter', 'with_closer'])
                    ->count(),
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildToolkitPayload(Workspace $workspace, User $user): array
    {
        $isAdmin = $user->isWorkspaceAdmin($workspace->id);

        return [
            'email_lists' => EmailList::query()
                ->where('workspace_id', $workspace->id)
                ->with('user:id,name')
                ->latest()
                ->limit(15)
                ->get()
                ->map(fn (EmailList $list) => [
                    'id' => $list->id,
                    'name' => $list->name,
                    'source_file' => $list->source_file,
                    'uploader' => $list->user?->name,
                    'total_count' => (int) $list->total_count,
                    'valid_count' => (int) $list->valid_count,
                    'invalid_count' => (int) $list->invalid_count,
                    'status' => $list->status,
                    'show_url' => $isAdmin
                        ? route('admin.lists.show', $list->id)
                        : route('portal.lists.show', $list->id),
                ])
                ->values()
                ->all(),
            'deliverability_tests' => DeliverabilityTest::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (DeliverabilityTest $test) => [
                    'id' => $test->id,
                    'domain' => $test->domain,
                    'status' => $test->status,
                    'overall_score' => $test->overall_score,
                    'created_at' => $test->created_at?->diffForHumans(),
                    'show_url' => $isAdmin
                        ? route('admin.deliverability.show', $test->id)
                        : route('portal.deliverability.show', $test->id),
                ])
                ->values()
                ->all(),
        ];
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
            'role_label' => \App\Support\SalesOps::roleLabel($member->pivot->role),
            'status' => $member->pivot->status ?? 'active',
            'is_owner' => $isOwner,
            'can_manage' => $viewer->canManageWorkspaceMembers($workspace->id) && ! $isOwner,
            'can_assign_modules' => $viewer->canAssignModulePermissions($workspace->id) && ! $isOwner,
            'module_summary' => $this->moduleSummaryForMember($member, $workspace),
        ];
    }

    protected function moduleSummaryForMember(User $member, Workspace $workspace): ?string
    {
        $role = $member->pivot->role ?? null;
        $summary = \App\Support\MemberModuleAccess::accessSummaryLabel(
            (string) $role,
            $member->getModulePermissions($workspace->id),
            $member->usesRestrictedModuleAccess($workspace->id),
        );

        return $summary === '—' ? null : $summary;
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
        return $user->adminSwitchableWorkspaces()
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

    protected function serializeWorkflow(Workflow $workflow, bool $light = false): array
    {
        $enriched = (int) ($workflow->enriched_leads ?? 0);
        $failed = (int) ($workflow->failed_leads ?? 0);
        $attempted = $enriched + $failed;

        $payload = [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'status' => $workflow->status,
            'original_filename' => $workflow->original_filename,
            'total_leads' => $workflow->total_leads,
            'processed_leads' => $workflow->processed_leads,
            'failed_leads' => $failed,
            'enriched_leads' => $enriched,
            'attempted_leads' => $attempted,
            'completion_pct' => $workflow->total_leads > 0
                ? (int) round(($attempted / $workflow->total_leads) * 100)
                : 0,
            'import_tag_ids' => $workflow->import_tag_ids ?? [],
            'lead_list_name' => $workflow->leadList?->name,
            'discarded_duplicates' => (int) ($workflow->discarded_duplicates ?? 0),
            'assigned_leads' => (int) ($workflow->assigned_leads_count ?? 0),
            'ready_to_assign' => (int) ($workflow->ready_to_assign_count ?? 0),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];

        if ($light) {
            return $payload;
        }

        return [
            ...$payload,
            'imported_leads' => (int) ($workflow->imported_leads_count ?? 0),
            'extracting_leads' => (int) ($workflow->extracting_leads_count ?? 0),
            'pending_verification' => (int) ($workflow->pending_verification_count ?? 0),
            'pipeline_steps' => PipelineProgress::steps($workflow),
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
            'input_email' => $lead->input_email,
            'input_phone' => $lead->input_phone,
            'direct_email' => $lead->direct_email,
            'direct_phone' => $lead->direct_phone,
            'payment_processor' => $lead->payment_processor,
            'current_processor' => $lead->current_processor,
            'monthly_processing_volume' => $lead->monthly_processing_volume,
            'schedule_at' => $lead->schedule_at?->format('M j, g:i A'),
            'pipeline_phase' => $lead->pipeline_phase,
            'pipeline_phase_label' => SalesOps::pipelinePhaseLabel($lead->pipeline_phase),
            'setter_status' => $lead->setter_status,
            'setter_status_label' => SalesOps::setterStatusLabel($lead->setter_status),
            'closer_status' => $lead->closer_status,
            'closer_status_label' => SalesOps::closerStatusLabel($lead->closer_status),
            'stage' => $lead->stage,
            'stage_label' => SalesOps::crmStageLabel($lead->stage),
            'tier' => $lead->tier,
            'tier_label' => SalesOps::tierLabel($lead->tier),
            'contact_attempts' => (int) $lead->contact_attempts,
            'error_message' => $lead->error_message,
            'tags' => $lead->relationLoaded('tags')
                ? $lead->tags->map(fn ($tag) => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                ])->values()->all()
                : [],
            'lead_list_id' => $lead->lead_list_id,
            'lead_list_name' => $lead->relationLoaded('leadList') ? $lead->leadList?->name : null,
            'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
