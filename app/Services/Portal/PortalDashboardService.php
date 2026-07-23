<?php

namespace App\Services\Portal;

use App\Models\CommunicationCallLog;
use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\SdrPerformanceService;
use App\Support\SalesOps;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortalDashboardService
{
    public function __construct(
        protected SdrPerformanceService $performance,
    ) {}

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    protected function businessDayBounds(): array
    {
        $tz = (string) config('app.business_timezone', 'America/New_York');

        return [
            now($tz)->startOfDay(),
            now($tz)->endOfDay(),
        ];
    }

    /**
     * Dispositions that mean the agent reached a real discovery / pitched conversation.
     *
     * @return list<string>
     */
    protected function discoveryDispositionMatchers(): array
    {
        return [
            '%call back%',
            '%callback%',
            '%follow up%',
            '%follow-up%',
            '%not interested%',
            '%requested appointment%',
            '%appointment%',
            '%meeting%',
            '%no pitch%',
            '%corporate%',
            '%owner hung%',
            '%owner hang%',
            '%gatekeeper%',
            '%decision maker%',
            '%discovery%',
        ];
    }

    /**
     * @return list<string>
     */
    protected function meetingDispositionMatchers(): array
    {
        return [
            '%requested appointment%',
            '%appointment set%',
            '%appointment settled%',
            '%meeting booked%',
            '%meeting set%',
            '%booked appointment%',
        ];
    }

    public function forUser(User $user, Workspace $workspace): array
    {
        $route = $user->portalDashboardRoute();

        return match ($user->effectivePortalRole($workspace->id)) {
            'appointment_setter' => $this->setterDashboard($user, $workspace, $route),
            'appointment_setter_team_lead' => $this->setterTeamDashboard($workspace, $route),
            'closers_team_lead' => $this->closerTeamDashboard($workspace, $route),
            'closer' => $this->closerDashboard($user, $workspace, $route),
            default => [],
        };
    }

    public function setterDashboard(User $user, Workspace $workspace, string $route = 'portal.setter.dashboard'): array
    {
        $leadQuery = $this->userLeadQuery($workspace, $user->id, 'with_setter');

        return [
            'role' => 'appointment_setter',
            'dashboard_route' => $route,
            'daily' => $this->performance->dailyMetrics($user, $workspace),
            'weekly' => $this->performance->weeklyMetrics($user, $workspace),
            'kpis' => [
                ['label' => 'Active leads', 'value' => (clone $leadQuery)->count(), 'focus' => 'active'],
                ['label' => 'Follow-ups due', 'value' => $this->followUpsDueCount($workspace, $user->id, 'with_setter'), 'focus' => 'followups'],
                ['label' => 'Calls today', 'value' => $this->callsToday($workspace, $user->id), 'focus' => 'calls'],
                ['label' => 'Connected today', 'value' => $this->connectedToday($workspace, $user->id), 'focus' => 'calls'],
                ['label' => 'Settled this week', 'value' => $this->settledThisWeek($workspace, $user->id), 'focus' => 'settled'],
            ],
            'upcoming' => $this->upcomingCallbacks($workspace, $user->id, 'with_setter'),
            'tier_breakdown' => $this->tierBreakdown($workspace, $user->id, 'with_setter'),
        ];
    }

    public function setterTeamDashboard(Workspace $workspace, string $route = 'portal.setter-team.dashboard'): array
    {
        $setterIds = $this->activeMemberIds($workspace, ['appointment_setter']);
        $teamRollup = $this->teamActivityRollup($workspace, $setterIds);

        return [
            'role' => 'appointment_setter_team_lead',
            'dashboard_route' => $route,
            'kpis' => [
                ['label' => 'Team active leads', 'value' => $this->teamActiveLeads($workspace, $setterIds, 'with_setter'), 'focus' => 'active'],
                ['label' => 'Settled this week', 'value' => $this->teamSettledThisWeek($workspace, $setterIds), 'focus' => 'settled'],
                ['label' => 'Awaiting closer', 'value' => $this->handoffQueueCount($workspace), 'focus' => 'handoff'],
                ['label' => 'Team dials today', 'value' => $teamRollup['dials'], 'focus' => 'calls'],
            ],
            'team_activity' => $teamRollup,
            'leaderboard' => $this->performance->teamLeaderboard($workspace, 'week')
                ->filter(fn ($row) => in_array(
                    $this->memberRole($workspace, $row['user_id']),
                    ['appointment_setter', 'appointment_setter_team_lead'],
                    true
                ))
                ->take(5)
                ->values()
                ->all(),
            'setter_load' => $this->performance->sdrLoad($workspace)->take(6)->all(),
        ];
    }

    public function closerDashboard(User $user, Workspace $workspace, string $route = 'portal.closer.dashboard'): array
    {
        $statusCounts = $this->closerStatusCounts($workspace, $user->id);
        $weeklyCloses = $this->closesThisWeek($workspace, $user->id);
        $closeTarget = config('sales_ops.weekly_quotas.closes', 2);

        return [
            'role' => 'closer',
            'dashboard_route' => $route,
            'kpis' => [
                ['label' => 'Active pipeline', 'value' => $statusCounts['total'], 'focus' => 'active'],
                ['label' => 'Follow-ups due', 'value' => $this->followUpsDueCount($workspace, $user->id, 'with_closer'), 'focus' => 'followups'],
                ['label' => 'Revenue MTD', 'value' => '$'.number_format($this->revenueMtd($workspace, $user->id), 0)],
                ['label' => 'Calls today', 'value' => $this->callsToday($workspace, $user->id), 'focus' => 'calls'],
                ['label' => 'Connected today', 'value' => $this->connectedToday($workspace, $user->id), 'focus' => 'calls'],
            ],
            'status_breakdown' => $statusCounts['by_status'],
            'weekly_closes' => [
                'actual' => $weeklyCloses,
                'target' => $closeTarget,
                'pct' => min(100, $closeTarget > 0 ? round(($weeklyCloses / $closeTarget) * 100, 1) : 0),
            ],
            'upcoming' => $this->upcomingCallbacks($workspace, $user->id, 'with_closer'),
        ];
    }

    public function closerTeamDashboard(Workspace $workspace, string $route = 'portal.closer-team.dashboard'): array
    {
        $closerIds = $this->activeMemberIds($workspace, ['closer']);
        $handoffCount = $this->handoffQueueCount($workspace);

        return [
            'role' => 'closers_team_lead',
            'dashboard_route' => $route,
            'kpis' => [
                ['label' => 'Handoff queue', 'value' => $handoffCount, 'href' => route('portal.closer-team.queue')],
                ['label' => 'Team active leads', 'value' => $this->teamActiveLeads($workspace, $closerIds, 'with_closer'), 'focus' => 'active'],
                ['label' => 'Sales this week', 'value' => $this->teamSalesThisWeek($workspace, $closerIds), 'focus' => 'settled'],
                ['label' => 'Unworked new', 'value' => $this->unworkedNewLeads($workspace, $closerIds), 'focus' => 'unworked'],
            ],
            'revenue_mtd' => $this->teamRevenueMtd($workspace, $closerIds),
            'leaderboard' => $this->performance->teamLeaderboard($workspace, 'week')
                ->filter(fn ($row) => in_array(
                    $this->memberRole($workspace, $row['user_id']),
                    ['closer', 'closers_team_lead'],
                    true
                ))
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    public function adminOperationalSummary(Workspace $workspace): array
    {
        $overview = $this->performance->workspaceOverview($workspace);
        [$todayStart, $todayEnd] = $this->businessDayBounds();

        $todayActivity = LeadActivity::query()
            ->select('type', DB::raw('count(*) as total'))
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereIn('type', ['dial', 'conversation', 'discovery', 'meeting_booked'])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        $callsTodayQuery = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($todayStart, $todayEnd) {
                $query->whereBetween('started_at', [$todayStart, $todayEnd])
                    ->orWhere(function ($fallback) use ($todayStart, $todayEnd) {
                        $fallback->whereNull('started_at')
                            ->whereBetween('created_at', [$todayStart, $todayEnd]);
                    });
            });

        $totalCallsToday = (clone $callsTodayQuery)->count();
        $connectedToday = (clone $callsTodayQuery)
            ->where(function ($query) {
                $query->where('duration_sec', '>=', 20)
                    ->orWhere('meta->call_result', 'connected')
                    ->orWhere('meta->call_result', 'answered');
            })
            ->count();
        $dispositionedToday = (clone $callsTodayQuery)
            ->whereNotNull('disposition')
            ->where('disposition', '!=', '')
            ->count();

        // Dialer writes call logs + dispositions; SalesOps LeadActivity dial/conversation
        // types are rarely logged — derive team activity from live call data.
        $discoveriesToday = (clone $callsTodayQuery)
            ->whereNotNull('disposition')
            ->where('disposition', '!=', '')
            ->where(function ($query) {
                foreach ($this->discoveryDispositionMatchers() as $matcher) {
                    $query->orWhereRaw('LOWER(disposition) LIKE ?', [$matcher]);
                }
            })
            ->count();
        $meetingsToday = (clone $callsTodayQuery)
            ->whereNotNull('disposition')
            ->where('disposition', '!=', '')
            ->where(function ($query) {
                foreach ($this->meetingDispositionMatchers() as $matcher) {
                    $query->orWhereRaw('LOWER(disposition) LIKE ?', [$matcher]);
                }
            })
            ->count();

        return [
            'overview' => $overview,
            'handoff_queue' => $this->handoffQueueCount($workspace),
            'today_activity' => [
                'dials' => max($totalCallsToday, (int) ($todayActivity['dial'] ?? 0)),
                'conversations' => max($connectedToday, (int) ($todayActivity['conversation'] ?? 0)),
                'discoveries' => max($discoveriesToday, (int) ($todayActivity['discovery'] ?? 0)),
                'meetings' => max($meetingsToday, (int) ($todayActivity['meeting_booked'] ?? 0)),
            ],
            'total_calls_today' => $totalCallsToday,
            'connected_today' => $connectedToday,
            'dispositioned_today' => $dispositionedToday,
            'leaderboard' => $this->performance->teamLeaderboard($workspace, 'week')->take(5)->all(),
            'at_capacity_setters' => collect($overview['sdr_load'] ?? [])
                ->filter(fn ($row) => $row['at_capacity'] ?? false)
                ->count(),
        ];
    }

    protected function userLeadQuery(Workspace $workspace, int $userId, string $phase)
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_user_id', $userId)
            ->where('pipeline_phase', $phase);
    }

    protected function followUpsDueCount(Workspace $workspace, int $userId, string $phase): int
    {
        return $this->userLeadQuery($workspace, $userId, $phase)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->whereNotNull('followup_at')->where('followup_at', '<=', now());
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('schedule_at')->where('schedule_at', '<=', now());
                });
            })
            ->count();
    }

    protected function callsToday(Workspace $workspace, int $userId): int
    {
        [$todayStart, $todayEnd] = $this->businessDayBounds();

        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$todayStart, $todayEnd])
            ->count();
    }

    protected function connectedToday(Workspace $workspace, int $userId): int
    {
        [$todayStart, $todayEnd] = $this->businessDayBounds();
        $notConnected = [
            'no answer',
            'no-answer',
            'answering machine',
            'answer machine',
            'voicemail',
            'busy',
            'failed',
        ];

        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$todayStart, $todayEnd])
            ->whereNotNull('disposition')
            ->where('disposition', '!=', '')
            ->get(['disposition', 'duration_sec', 'meta'])
            ->filter(function (CommunicationCallLog $log) use ($notConnected) {
                $disposition = mb_strtolower(trim((string) $log->disposition));
                foreach ($notConnected as $label) {
                    if ($disposition === $label || str_contains($disposition, $label)) {
                        return false;
                    }
                }

                return true;
            })
            ->count();
    }

    protected function settledThisWeek(Workspace $workspace, int $userId): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_setter_id', $userId)
            ->where('appointment_settled_at', '>=', now()->startOfWeek())
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function upcomingCallbacks(Workspace $workspace, int $userId, string $phase): Collection
    {
        return $this->userLeadQuery($workspace, $userId, $phase)
            ->where(function ($q) {
                $q->whereNotNull('followup_at')
                    ->orWhereNotNull('schedule_at');
            })
            ->orderByRaw('COALESCE(followup_at, schedule_at) asc')
            ->limit(5)
            ->get(['id', 'business_name', 'owner_name', 'followup_at', 'schedule_at'])
            ->map(fn (WorkflowLead $lead) => [
                'id' => $lead->id,
                'name' => $lead->business_name ?: $lead->owner_name ?: 'Lead #'.$lead->id,
                'when' => ($lead->followup_at ?? $lead->schedule_at)?->format('M j, g:i A'),
                'overdue' => ($lead->followup_at ?? $lead->schedule_at)?->isPast() ?? false,
            ]);
    }

    /**
     * @return array<string, int>
     */
    protected function tierBreakdown(Workspace $workspace, int $userId, string $phase): array
    {
        return $this->userLeadQuery($workspace, $userId, $phase)
            ->select('tier', DB::raw('count(*) as total'))
            ->groupBy('tier')
            ->pluck('total', 'tier')
            ->all();
    }

    /**
     * @param  list<string>  $roles
     * @return list<int>
     */
    protected function activeMemberIds(Workspace $workspace, array $roles): array
    {
        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', $roles)
            ->pluck('users.id')
            ->all();
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{dials: int, conversations: int, discoveries: int, meetings: int}
     */
    protected function teamActivityRollup(Workspace $workspace, array $memberIds): array
    {
        if ($memberIds === []) {
            return ['dials' => 0, 'conversations' => 0, 'discoveries' => 0, 'meetings' => 0];
        }

        $rows = LeadActivity::query()
            ->select('type', DB::raw('count(*) as total'))
            ->whereIn('user_id', $memberIds)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->whereIn('type', ['dial', 'conversation', 'discovery', 'meeting_booked'])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();

        return [
            'dials' => (int) ($rows['dial'] ?? 0),
            'conversations' => (int) ($rows['conversation'] ?? 0),
            'discoveries' => (int) ($rows['discovery'] ?? 0),
            'meetings' => (int) ($rows['meeting_booked'] ?? 0),
        ];
    }

    /**
     * @param  list<int>  $memberIds
     */
    protected function teamActiveLeads(Workspace $workspace, array $memberIds, string $phase): int
    {
        if ($memberIds === []) {
            return 0;
        }

        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('assigned_user_id', $memberIds)
            ->where('pipeline_phase', $phase)
            ->count();
    }

    /**
     * @param  list<int>  $setterIds
     */
    protected function teamSettledThisWeek(Workspace $workspace, array $setterIds): int
    {
        if ($setterIds === []) {
            return 0;
        }

        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('assigned_setter_id', $setterIds)
            ->where('appointment_settled_at', '>=', now()->startOfWeek())
            ->count();
    }

    public function handoffQueueCount(Workspace $workspace): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('pipeline_phase', 'appointment_settled')
            ->whereNull('assigned_closer_id')
            ->count();
    }

    /**
     * @return array{total: int, by_status: array<string, int>}
     */
    protected function closerStatusCounts(Workspace $workspace, int $userId): array
    {
        $rows = $this->userLeadQuery($workspace, $userId, 'with_closer')
            ->select('closer_status', DB::raw('count(*) as total'))
            ->groupBy('closer_status')
            ->pluck('total', 'closer_status')
            ->all();

        return [
            'total' => array_sum($rows),
            'by_status' => $rows,
        ];
    }

    protected function closesThisWeek(Workspace $workspace, int $userId): int
    {
        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_closer_id', $userId)
            ->where('closer_status', 'sale_made')
            ->where('updated_at', '>=', now()->startOfWeek())
            ->count();
    }

    protected function revenueMtd(Workspace $workspace, int $userId): float
    {
        return (float) WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->where('assigned_closer_id', $userId)
            ->where('closer_status', 'sale_made')
            ->where('updated_at', '>=', now()->startOfMonth())
            ->sum('sale_value');
    }

    /**
     * @param  list<int>  $closerIds
     */
    protected function teamSalesThisWeek(Workspace $workspace, array $closerIds): int
    {
        if ($closerIds === []) {
            return 0;
        }

        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('assigned_closer_id', $closerIds)
            ->where('closer_status', 'sale_made')
            ->where('updated_at', '>=', now()->startOfWeek())
            ->count();
    }

    /**
     * @param  list<int>  $closerIds
     */
    protected function unworkedNewLeads(Workspace $workspace, array $closerIds): int
    {
        if ($closerIds === []) {
            return 0;
        }

        return WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('assigned_user_id', $closerIds)
            ->where('pipeline_phase', 'with_closer')
            ->where('closer_status', 'new')
            ->count();
    }

    /**
     * @param  list<int>  $closerIds
     */
    protected function teamRevenueMtd(Workspace $workspace, array $closerIds): float
    {
        if ($closerIds === []) {
            return 0.0;
        }

        return (float) WorkflowLead::query()
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereIn('assigned_closer_id', $closerIds)
            ->where('closer_status', 'sale_made')
            ->where('updated_at', '>=', now()->startOfMonth())
            ->sum('sale_value');
    }

    protected function memberRole(Workspace $workspace, int $userId): ?string
    {
        $member = $workspace->users()->where('users.id', $userId)->first();
        $role = $member?->pivot?->role;

        return $role ? SalesOps::normalizeLegacyRole($role) : null;
    }

    /**
     * Flat metrics payload for portal dashboard polling.
     *
     * @return array<string, mixed>
     */
    public function metricsPayload(User $user, Workspace $workspace): array
    {
        $dashboard = $this->forUser($user, $workspace);

        return [
            'role' => $dashboard['role'] ?? null,
            'metrics' => $this->flattenMetrics($dashboard),
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    public function flattenMetrics(array $dashboard): array
    {
        $metrics = [];

        foreach ($dashboard['kpis'] ?? [] as $index => $kpi) {
            $key = $kpi['focus'] ?? ('kpi_'.$index);
            $value = $kpi['value'];
            if (is_string($value) && str_starts_with($value, '$')) {
                $metrics[$key] = (float) preg_replace('/[^\d.]/', '', $value);
                $metrics[$key.'_formatted'] = $value;
            } else {
                $metrics[$key] = $value;
            }
        }

        foreach ($dashboard['daily'] ?? [] as $key => $metric) {
            $metrics['daily_'.$key.'_actual'] = $metric['actual'] ?? 0;
            $metrics['daily_'.$key.'_target'] = $metric['target'] ?? 0;
            $metrics['daily_'.$key.'_pct'] = $metric['pct'] ?? 0;
        }

        if (! empty($dashboard['weekly_closes'])) {
            $metrics['weekly_closes_actual'] = $dashboard['weekly_closes']['actual'] ?? 0;
            $metrics['weekly_closes_target'] = $dashboard['weekly_closes']['target'] ?? 0;
            $metrics['weekly_closes_pct'] = $dashboard['weekly_closes']['pct'] ?? 0;
        }

        foreach ($dashboard['tier_breakdown'] ?? [] as $tier => $count) {
            $metrics['tier_'.$tier] = $count;
        }

        foreach ($dashboard['status_breakdown'] ?? [] as $status => $count) {
            $metrics['status_'.$status] = $count;
        }

        foreach ($dashboard['team_activity'] ?? [] as $key => $count) {
            $metrics['team_'.$key] = $count;
        }

        if (isset($dashboard['revenue_mtd'])) {
            $metrics['revenue_mtd'] = $dashboard['revenue_mtd'];
        }

        return $metrics;
    }
}
