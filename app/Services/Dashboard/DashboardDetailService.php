<?php

namespace App\Services\Dashboard;

use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadReactivationService;
use App\Services\SalesOps\SdrPerformanceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardDetailService
{
    public function __construct(
        protected LeadReactivationService $reactivation,
        protected SdrPerformanceService $performance,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function resolveAdmin(Request $request, Workspace $workspace): ?array
    {
        $detail = $request->string('detail')->toString();
        if ($detail === '') {
            return null;
        }

        $workflowIds = $workspace->workflows()->pluck('id')->all();
        $query = WorkflowLead::query()
            ->with(['workflow', 'assignee', 'setter', 'closer'])
            ->whereIn('workflow_id', $workflowIds)
            ->orderByDesc('updated_at');

        $meta = match ($detail) {
            'ops-active' => [
                'title' => 'Active CRM leads',
                'description' => 'Enriched leads still in pipeline — not closed won or lost.',
                'count_query' => (clone $query)
                    ->where('status', 'completed')
                    ->whereNotIn('stage', ['closed_won', 'closed_lost']),
                'workflows_link' => ['phase' => null],
            ],
            'ops-verification' => [
                'title' => 'Awaiting verification',
                'description' => 'Leads pending manual verification before enrichment completes.',
                'count_query' => (clone $query)->where('status', 'pending_verification'),
                'workflows_link' => ['search' => null, 'phase' => 'imported'],
            ],
            'ops-reactivation' => [
                'title' => 'Reactivation queue',
                'description' => 'Stale or lost leads eligible for reactivation outreach.',
                'count_query' => null,
                'workflows_link' => [],
            ],
            'ops-handoff' => [
                'title' => 'Handoff queue',
                'description' => 'Appointments settled and waiting for closer assignment.',
                'count_query' => (clone $query)
                    ->where('pipeline_phase', 'appointment_settled')
                    ->whereNull('assigned_closer_id'),
                'workflows_link' => ['phase' => 'appointment_settled'],
            ],
            'pipeline' => $this->adminPipelineDetail($request, $query, $workflowIds),
            'user' => $this->adminUserDetail($request, $query, $workspace),
            'activity' => $this->adminActivityDetail($request, $workspace),
            'performer' => $this->adminPerformerDetail($request, $workspace),
            'workflow' => $this->adminWorkflowDetail($request, $query, $workspace),
            default => null,
        };

        if ($meta === null) {
            return null;
        }

        if ($detail === 'ops-reactivation') {
            $candidates = $this->reactivation->candidates($workspace, 500);
            $leadIds = $candidates->pluck('id')->all();

            return array_merge($meta, [
                'key' => $detail,
                'total' => $candidates->count(),
                'leads' => $this->paginateIds($query, $leadIds, $request),
                'stats' => $this->leadStats($candidates),
            ]);
        }

        if ($detail === 'activity') {
            return $meta;
        }

        if ($detail === 'performer') {
            return $meta;
        }

        /** @var \Illuminate\Database\Eloquent\Builder $countQuery */
        $countQuery = $meta['count_query'] ?? $query;
        $total = (clone $countQuery)->count();

        return array_merge($meta, [
            'key' => $detail,
            'params' => $request->only(['detail', 'metric', 'phase', 'user_id', 'workflow_id', 'type']),
            'total' => $total,
            'leads' => (clone $countQuery)->paginate(15)->withQueryString(),
            'stats' => $this->summarizeLeads((clone $countQuery)),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolvePortalFocus(Request $request, Workspace $workspace, User $user): ?array
    {
        $focus = $request->string('focus')->toString();
        if ($focus === '') {
            return null;
        }

        $role = $user->effectivePortalRole($workspace->id);

        return match ($focus) {
            'active' => [
                'title' => 'Active leads',
                'description' => 'Leads currently assigned and in your working queue.',
            ],
            'followups' => [
                'title' => 'Follow-ups due',
                'description' => 'Leads with overdue or due callbacks and scheduled follow-ups.',
            ],
            'settled' => [
                'title' => 'Settled this week',
                'description' => 'Appointments settled during the current week.',
            ],
            'calls' => [
                'title' => 'Calls today',
                'description' => 'Outbound and inbound calls logged today.',
            ],
            'handoff' => [
                'title' => 'Handoff queue',
                'description' => 'Settled appointments awaiting closer pickup.',
            ],
            'unworked' => [
                'title' => 'Unworked new leads',
                'description' => 'Recently assigned leads with no activity logged yet.',
            ],
            'tier' => [
                'title' => 'Tier: '.ucfirst(str_replace('_', ' ', $request->input('tier', 'unassigned'))),
                'description' => 'Leads filtered by lead tier assignment.',
            ],
            'status' => [
                'title' => config('sales_ops.closer_statuses.'.$request->input('status'), 'Pipeline status'),
                'description' => 'Leads filtered by closer pipeline status.',
            ],
            'callbacks' => [
                'title' => 'Upcoming callbacks',
                'description' => 'Scheduled follow-ups and callbacks on your calendar.',
            ],
            'member' => [
                'title' => User::find($request->integer('member'))?->name ?? 'Team member',
                'description' => 'Leads assigned to this team member.',
            ],
            default => null,
        };
    }

    public function adminDetailUrl(string $detail, array $params = []): string
    {
        return route('admin.dashboard', array_merge(['detail' => $detail], $params));
    }

    /**
     * @param  list<int>  $leadIds
     */
    protected function paginateIds($query, array $leadIds, Request $request): LengthAwarePaginator
    {
        if ($leadIds === []) {
            return $query->whereRaw('0 = 1')->paginate(15)->withQueryString();
        }

        return $query->whereIn('id', $leadIds)->paginate(15)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function adminPipelineDetail(Request $request, $query, array $workflowIds): array
    {
        $metric = $request->string('metric')->toString();
        $phase = $request->string('phase')->toString();

        $labels = [
            'total_leads' => 'All leads',
            'new' => 'New leads',
            'qualified' => 'Qualified leads',
            'booked' => 'Booked appointments',
            'showed' => 'Showed / in closer pipeline',
            'closed_won' => 'Closed won',
            'not_now' => 'Not now / nurture',
            'dead' => 'Dead / closed lost',
        ];

        $filtered = clone $query;

        if ($phase !== '') {
            $filtered->where('pipeline_phase', $phase);
            $title = config('sales_ops.pipeline_phases.'.$phase, ucfirst(str_replace('_', ' ', $phase)));
        } else {
            match ($metric) {
                'new' => $filtered->whereIn('stage', ['new_lead', 'new', 'imported']),
                'qualified' => $filtered->where(function ($q) {
                    $q->where('meeting_qualified', true)
                        ->orWhereIn('stage', ['connected', 'discovery_completed']);
                }),
                'booked' => $filtered->where(function ($q) {
                    $q->whereNotNull('appointment_settled_at')
                        ->orWhere('stage', 'meeting_scheduled');
                }),
                'showed' => $filtered->whereIn('stage', ['proposal_sent', 'follow_up', 'closed_won', 'closed_lost']),
                'closed_won' => $filtered->where(function ($q) {
                    $q->where('stage', 'closed_won')->orWhere('closer_status', 'sale_made');
                }),
                'not_now' => $filtered->where(function ($q) {
                    $q->where('setter_status', 'not_interested')->orWhere('closer_status', 'follow_up');
                }),
                'dead' => $filtered->where(function ($q) {
                    $q->where('stage', 'closed_lost')->orWhere('closer_status', 'closed_lost');
                }),
                default => null,
            };
            $title = $labels[$metric] ?? 'Pipeline leads';
        }

        return [
            'title' => $title,
            'description' => 'Filtered pipeline view — counts update in real time.',
            'count_query' => $filtered,
            'workflows_link' => array_filter([
                'phase' => $phase ?: ($metric === 'new' ? 'imported' : null),
                'search' => $metric === 'dead' ? 'closed_lost' : null,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function adminUserDetail(Request $request, $query, Workspace $workspace): ?array
    {
        $userId = $request->integer('user_id');
        if ($userId <= 0) {
            return null;
        }

        $member = $workspace->users()->where('users.id', $userId)->first();
        if (! $member) {
            return null;
        }

        $filtered = (clone $query)->where(function ($q) use ($userId) {
            $q->where('assigned_user_id', $userId)
                ->orWhere('assigned_setter_id', $userId)
                ->orWhere('assigned_closer_id', $userId);
        });

        return [
            'title' => $member->name,
            'description' => 'All leads assigned to this team member across setter and closer roles.',
            'count_query' => $filtered,
            'workflows_link' => ['assigned_user_id' => $userId],
            'user' => ['id' => $member->id, 'name' => $member->name, 'role' => $member->pivot->role ?? ''],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function adminActivityDetail(Request $request, Workspace $workspace): ?array
    {
        $typeMap = [
            'dials' => 'dial',
            'conversations' => 'conversation',
            'discoveries' => 'discovery',
            'meetings' => 'meeting_booked',
        ];
        $type = $request->string('type')->toString();
        $activityType = $typeMap[$type] ?? null;
        if ($activityType === null) {
            return null;
        }

        $labels = [
            'dials' => 'Dials today',
            'conversations' => 'Conversations today',
            'discoveries' => 'Discoveries today',
            'meetings' => 'Meetings booked today',
        ];

        $activities = LeadActivity::query()
            ->with(['user', 'lead'])
            ->where('type', $activityType)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return [
            'key' => 'activity',
            'title' => $labels[$type] ?? 'Team activity',
            'description' => 'Live activity log for today — refreshes automatically.',
            'activities' => $activities,
            'total' => $activities->total(),
            'activity_type' => $type,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function adminPerformerDetail(Request $request, Workspace $workspace): ?array
    {
        $userId = $request->integer('user_id');
        if ($userId <= 0) {
            return null;
        }

        $member = $workspace->users()->where('users.id', $userId)->first();
        if (! $member) {
            return null;
        }

        $start = now()->startOfWeek();
        $counts = LeadActivity::query()
            ->select('type', DB::raw('count(*) as total'))
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$start, now()])
            ->whereIn('type', ['dial', 'conversation', 'discovery', 'meeting_booked'])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $funded = WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_closer_id', $userId)
            ->where(function ($q) {
                $q->where('stage', 'closed_won')->orWhere('closer_status', 'sale_made');
            })
            ->where('updated_at', '>=', $start)
            ->count();

        return [
            'key' => 'performer',
            'title' => $member->name,
            'description' => 'Weekly performance breakdown for this team member.',
            'total' => array_sum($counts) + $funded,
            'stats' => [
                ['label' => 'Dials', 'value' => (int) ($counts['dial'] ?? 0)],
                ['label' => 'Conversations', 'value' => (int) ($counts['conversation'] ?? 0)],
                ['label' => 'Discoveries', 'value' => (int) ($counts['discovery'] ?? 0)],
                ['label' => 'Meetings', 'value' => (int) ($counts['meeting_booked'] ?? 0)],
                ['label' => 'Deals funded', 'value' => $funded],
            ],
            'user' => ['id' => $member->id, 'name' => $member->name],
            'workflows_link' => ['assigned_user_id' => $userId],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function adminWorkflowDetail(Request $request, $query, Workspace $workspace): ?array
    {
        $workflowId = $request->integer('workflow_id');
        if ($workflowId <= 0) {
            return null;
        }

        $workflow = $workspace->workflows()->find($workflowId);
        if (! $workflow) {
            return null;
        }

        $filtered = (clone $query)->where('workflow_id', $workflowId);

        return [
            'title' => $workflow->name,
            'description' => $workflow->original_filename.' · imported '.$workflow->created_at?->toDateString(),
            'count_query' => $filtered,
            'workflow' => ['id' => $workflow->id, 'name' => $workflow->name],
            'workflows_link' => [],
            'workflow_show' => route('admin.workflows.show', $workflow->id),
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Support\Collection  $source
     * @return list<array{label: string, value: int|string}>
     */
    protected function summarizeLeads($source): array
    {
        if ($source instanceof \Illuminate\Support\Collection) {
            return [
                ['label' => 'Total', 'value' => $source->count()],
            ];
        }

        $base = clone $source;

        return [
            ['label' => 'Total', 'value' => (clone $base)->count()],
            ['label' => 'With setter', 'value' => (clone $base)->where('pipeline_phase', 'with_setter')->count()],
            ['label' => 'With closer', 'value' => (clone $base)->where('pipeline_phase', 'with_closer')->count()],
            ['label' => 'Settled', 'value' => (clone $base)->where('pipeline_phase', 'appointment_settled')->count()],
        ];
    }

    /**
     * @return list<array{label: string, value: int|string}>
     */
    protected function leadStats(\Illuminate\Support\Collection $leads): array
    {
        return [
            ['label' => 'Eligible', 'value' => $leads->count()],
            ['label' => 'Follow-up stage', 'value' => $leads->where('stage', 'follow_up')->count()],
            ['label' => 'Closed lost', 'value' => $leads->where('stage', 'closed_lost')->count()],
        ];
    }
}
