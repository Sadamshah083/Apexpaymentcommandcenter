<?php

namespace App\Services\Dashboard;

use App\Models\CommunicationCallLog;
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
        protected PipelineMetricsService $pipelineMetrics,
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
                'description' => 'Leads actively in setter or closer pipeline — not closed.',
                'count_query' => (clone $query)->where(function ($q) {
                    $this->pipelineMetrics->scopeActivePipeline($q);
                }),
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

        $filtered = clone $query;

        if ($phase !== '') {
            $filtered->where('pipeline_phase', $phase);
            $title = config('sales_ops.pipeline_phases.'.$phase, ucfirst(str_replace('_', ' ', $phase)));
        } elseif ($metric !== '' && in_array($metric, $this->pipelineMetrics->metricKeys(), true)) {
            $this->pipelineMetrics->applyMetric($filtered, $metric);
            $title = $this->pipelineMetrics->metricLabel($metric);
        } else {
            $title = 'Pipeline leads';
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
     * @param  array<string, mixed>|null  $detail
     * @return array<string, mixed>|null
     */
    public function toRealtimePayload(?array $detail): ?array
    {
        if ($detail === null) {
            return null;
        }

        $payload = [
            'key' => $detail['key'] ?? null,
            'total' => $detail['total'] ?? 0,
            'stats' => $detail['stats'] ?? [],
        ];

        if (! empty($detail['activities'])) {
            $payload['activities'] = collect($detail['activities']->items())->map(fn (LeadActivity $activity) => [
                'user_name' => $activity->user?->name ?? '—',
                'lead_id' => $activity->lead_id,
                'lead_name' => $activity->lead?->business_name ?: 'Lead #'.$activity->lead_id,
                'workflow_id' => $activity->lead?->workflow_id,
                'type' => str_replace('_', ' ', $activity->type),
                'when' => $activity->created_at?->diffForHumans() ?? '—',
            ])->values()->all();
        }

        if (! empty($detail['leads']) && $detail['leads']->currentPage() === 1) {
            $payload['leads'] = collect($detail['leads']->items())->map(fn (WorkflowLead $lead) => [
                'id' => $lead->id,
                'name' => $lead->business_name ?: $lead->owner_name ?: 'Lead #'.$lead->id,
                'workflow_name' => $lead->workflow?->name,
                'workflow_id' => $lead->workflow_id,
                'pipeline_phase' => str_replace('_', ' ', $lead->pipeline_phase ?? '—'),
                'stage' => str_replace('_', ' ', $lead->stage ?? '—'),
                'assignee' => $lead->assignee?->name ?? $lead->setter?->name ?? $lead->closer?->name ?? '—',
                'updated' => $lead->updated_at?->diffForHumans(short: true) ?? '—',
            ])->values()->all();
        }

        return $payload;
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

        $tz = (string) config('app.business_timezone', 'America/New_York');
        $todayStart = now($tz)->startOfDay();
        $todayEnd = now($tz)->endOfDay();

        $activities = LeadActivity::query()
            ->with(['user', 'lead'])
            ->where('type', $activityType)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        // Dialer activity lives in call logs — use those when SalesOps activity rows are empty.
        $callLogs = null;
        if ($activities->total() === 0) {
            $callLogs = $this->todayActivityCallLogs($workspace, $type, $todayStart, $todayEnd);
        }

        return [
            'key' => 'activity',
            'title' => $labels[$type] ?? 'Team activity',
            'description' => 'Live activity for today — sourced from dialer call logs and team activity.',
            'activities' => $activities,
            'call_logs' => $callLogs,
            'total' => $callLogs?->total() ?? $activities->total(),
            'activity_type' => $type,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, CommunicationCallLog>
     */
    protected function todayActivityCallLogs(Workspace $workspace, string $type, $todayStart, $todayEnd): LengthAwarePaginator
    {
        $query = CommunicationCallLog::query()
            ->with('user')
            ->where('workspace_id', $workspace->id)
            ->where(function ($q) use ($todayStart, $todayEnd) {
                $q->whereBetween('started_at', [$todayStart, $todayEnd])
                    ->orWhere(function ($fallback) use ($todayStart, $todayEnd) {
                        $fallback->whereNull('started_at')
                            ->whereBetween('created_at', [$todayStart, $todayEnd]);
                    });
            });

        if ($type === 'conversations') {
            $query->where(function ($q) {
                $q->where('duration_sec', '>=', 20)
                    ->orWhere('meta->call_result', 'connected')
                    ->orWhere('meta->call_result', 'answered');
            });
        } elseif ($type === 'discoveries') {
            $query->whereNotNull('disposition')
                ->where('disposition', '!=', '')
                ->where(function ($q) {
                    foreach ([
                        '%call back%', '%callback%', '%follow up%', '%follow-up%',
                        '%not interested%', '%requested appointment%', '%appointment%',
                        '%meeting%', '%no pitch%', '%corporate%', '%owner hung%',
                        '%owner hang%', '%gatekeeper%', '%decision maker%', '%discovery%',
                    ] as $matcher) {
                        $q->orWhereRaw('LOWER(disposition) LIKE ?', [$matcher]);
                    }
                });
        } elseif ($type === 'meetings') {
            $query->whereNotNull('disposition')
                ->where('disposition', '!=', '')
                ->where(function ($q) {
                    foreach ([
                        '%requested appointment%', '%appointment set%', '%appointment settled%',
                        '%meeting booked%', '%meeting set%', '%booked appointment%',
                    ] as $matcher) {
                        $q->orWhereRaw('LOWER(disposition) LIKE ?', [$matcher]);
                    }
                });
        }

        return $query->orderByDesc('started_at')->orderByDesc('id')->paginate(15)->withQueryString();
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
        $end = now()->endOfWeek();
        $counts = LeadActivity::query()
            ->select('type', DB::raw('count(*) as total'))
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', ['dial', 'conversation', 'discovery', 'meeting_booked'])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $funded = $this->pipelineMetrics
            ->scopeClosedWon(WorkflowLead::query()
                ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id)))
            ->where('assigned_closer_id', $userId)
            ->where('updated_at', '>=', $start)
            ->count();

        $callStats = $this->performance->callStatsByUser($workspace, [$userId], $start, $end)[$userId] ?? [
            'calls' => 0,
            'talk_sec' => 0,
            'disposed' => 0,
            'connected' => 0,
        ];
        $callsTaken = (int) ($callStats['calls'] ?? 0);
        $talkSec = (int) ($callStats['talk_sec'] ?? 0);
        $avgSec = $callsTaken > 0 ? (int) round($talkSec / $callsTaken) : 0;

        $callLogs = $this->performance->agentCallHistory($workspace, $userId, $start, $end, 25);
        $calls = $callLogs->getCollection()->map(function ($row) {
            $duration = (int) ($row->duration_sec ?? 0);
            $disposition = trim((string) ($row->disposition ?: data_get($row->meta, 'disposition', '')));
            $phone = $row->to_phone ?: $row->from_phone ?: '—';
            $when = $row->started_at ?? $row->created_at;

            return [
                'phone' => $phone,
                'direction' => $row->direction ?: 'outbound',
                'duration_sec' => $duration,
                'duration_label' => $this->performance->formatTalkDuration($duration),
                'disposition' => $disposition !== '' ? $disposition : '—',
                'status' => $duration > 0 ? 'Connected' : ucfirst((string) ($row->status ?: 'completed')),
                'note' => trim((string) ($row->note ?? '')),
                'when' => $when?->diffForHumans() ?? '—',
                'when_exact' => $when?->timezone(config('app.timezone'))->format('D M j · g:i A') ?? '—',
            ];
        })->values()->all();

        return [
            'key' => 'performer',
            'title' => $member->name,
            'description' => 'Weekly call activity — clicks from the leaderboard show duration, disposition, and outcomes.',
            'total' => $callsTaken,
            'total_label' => 'calls this week',
            'stats' => [
                ['label' => 'Calls taken', 'value' => $callsTaken],
                ['label' => 'Talk time', 'value' => $this->performance->formatTalkDuration($talkSec)],
                ['label' => 'Avg duration', 'value' => $this->performance->formatTalkDuration($avgSec)],
                ['label' => 'Connected', 'value' => (int) ($callStats['connected'] ?? 0)],
                ['label' => 'Dispositions', 'value' => (int) ($callStats['disposed'] ?? 0)],
                ['label' => 'Meetings', 'value' => (int) ($counts['meeting_booked'] ?? 0)],
                ['label' => 'Deals funded', 'value' => $funded],
            ],
            'calls' => $calls,
            'calls_paginator' => $callLogs,
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
            ['label' => 'Closed lost', 'value' => $leads->where('closer_status', 'closed_lost')->count()],
        ];
    }
}
