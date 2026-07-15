<?php

namespace App\Services\SalesOps;

use App\Models\CommunicationCallLog;
use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Dashboard\PipelineMetricsService;
use App\Support\SalesOps;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SdrPerformanceService
{
    public function dailyMetrics(User $user, Workspace $workspace, ?Carbon $date = null): array
    {
        $date = ($date ?? now())->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $counts = $this->activityCounts($user, $workspace, $date, $end);
        $quotas = config('sales_ops.daily_quotas', []);

        return [
            'date' => $date->toDateString(),
            'dials' => $this->metric('dials', $counts, $quotas),
            'conversations' => $this->metric('conversations', $counts, $quotas),
            'decision_maker_contacts' => $this->metric('decision_maker_contacts', $counts, $quotas),
            'discoveries' => $this->metric('discoveries', $counts, $quotas),
        ];
    }

    public function weeklyMetrics(User $user, Workspace $workspace, ?Carbon $start = null): array
    {
        $start = ($start ?? now())->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $counts = $this->activityCounts($user, $workspace, $start, $end);
        $quotas = config('sales_ops.weekly_quotas', []);

        $meetings = LeadActivity::query()
            ->where('user_id', $user->id)
            ->where('type', 'meeting_booked')
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->count();

        return [
            'week_start' => $start->toDateString(),
            'discoveries' => $this->metric('discoveries', $counts, $quotas),
            'qualified_meetings' => [
                'actual' => $meetings,
                'target' => $quotas['qualified_meetings'] ?? 2,
                'pct' => $this->pct($meetings, $quotas['qualified_meetings'] ?? 2),
            ],
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function teamLeaderboard(Workspace $workspace, string $period = 'week'): Collection
    {
        $start = $period === 'day' ? now()->startOfDay() : now()->startOfWeek();
        $end = $period === 'day' ? now()->endOfDay() : now()->endOfWeek();

        $map = [
            'dial' => 'dial',
            'conversation' => 'conversation',
            'decision_maker' => 'decision_maker',
            'discovery' => 'discovery',
            'meeting_booked' => 'meeting_booked',
        ];

        $members = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', array_merge(SalesOps::sdrRoles(), ['account_executive']))
            ->get();

        $memberIds = $members->pluck('id')->all();

        $activityRows = LeadActivity::query()
            ->select('user_id', 'type', DB::raw('count(*) as total'))
            ->whereIn('user_id', $memberIds)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', array_keys($map))
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('user_id', 'type')
            ->get()
            ->groupBy('user_id');

        $fundedCounts = WorkflowLead::query()
            ->select('assigned_user_id', DB::raw('count(*) as total'))
            ->whereIn('assigned_user_id', $memberIds)
            ->where('stage', 'closed_won')
            ->where('updated_at', '>=', $start)
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id')
            ->all();

        $callStats = $this->callStatsByUser($workspace, $memberIds, $start, $end);

        return $members
            ->map(function (User $member) use ($activityRows, $fundedCounts, $map, $callStats) {
                $userActivities = $activityRows->get($member->id) ?? collect();
                $counts = [];
                foreach ($map as $type => $key) {
                    $counts[$key] = (int) ($userActivities->firstWhere('type', $type)?->total ?? 0);
                }

                $funded = (int) ($fundedCounts[$member->id] ?? 0);
                $calls = $callStats[$member->id] ?? [
                    'calls' => 0,
                    'talk_sec' => 0,
                    'disposed' => 0,
                    'connected' => 0,
                ];
                $callsTaken = (int) ($calls['calls'] ?? 0);
                $talkSec = (int) ($calls['talk_sec'] ?? 0);
                // Prefer live dialer call count when activity dials are missing/stale.
                $dials = max((int) ($counts['dial'] ?? 0), $callsTaken);

                return [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'role' => SalesOps::roleLabel($member->pivot->role ?? null),
                    'dials' => $dials,
                    'calls' => $callsTaken,
                    'calls_taken' => $callsTaken,
                    'talk_sec' => $talkSec,
                    'talk_label' => $this->formatTalkDuration($talkSec),
                    'disposed' => (int) ($calls['disposed'] ?? 0),
                    'connected' => (int) ($calls['connected'] ?? 0),
                    'conversations' => $counts['conversation'] ?? 0,
                    'discoveries' => $counts['discovery'] ?? 0,
                    'meetings' => $counts['meeting_booked'] ?? 0,
                    'deals_funded' => $funded,
                    'score' => ($counts['discovery'] ?? 0) * 10
                        + ($counts['meeting_booked'] ?? 0) * 25
                        + $funded * 100
                        + ($callsTaken * 2)
                        + (int) floor($talkSec / 60),
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Weekly (or daily) dialer call stats keyed by user_id.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{calls: int, talk_sec: int, disposed: int, connected: int}>
     */
    public function callStatsByUser(Workspace $workspace, array $userIds, Carbon $start, Carbon $end): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = CommunicationCallLog::query()
            ->select([
                'user_id',
                DB::raw('COUNT(*) as calls'),
                DB::raw('COALESCE(SUM(COALESCE(duration_sec, 0)), 0) as talk_sec'),
                DB::raw('SUM(CASE WHEN disposition IS NOT NULL AND disposition != \'\' THEN 1 ELSE 0 END) as disposed'),
                DB::raw('SUM(CASE WHEN COALESCE(duration_sec, 0) > 0 THEN 1 ELSE 0 END) as connected'),
            ])
            ->where('workspace_id', $workspace->id)
            ->whereIn('user_id', $userIds)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('started_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNull('started_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->groupBy('user_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->user_id] = [
                'calls' => (int) $row->calls,
                'talk_sec' => (int) $row->talk_sec,
                'disposed' => (int) $row->disposed,
                'connected' => (int) $row->connected,
            ];
        }

        return $out;
    }

    /**
     * Recent dialer calls for one agent (week detail table).
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function agentCallHistory(Workspace $workspace, int $userId, Carbon $start, Carbon $end, int $perPage = 25)
    {
        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $userId)
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('started_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNull('started_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderByDesc(DB::raw('COALESCE(started_at, created_at)'))
            ->paginate($perPage)
            ->withQueryString();
    }

    public function formatTalkDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $hours = intdiv($seconds, 3600);
        $mins = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
        }

        return "{$mins}m";
    }

    public function workspaceOverview(Workspace $workspace): array
    {
        $workflowIds = $workspace->workflows()->pluck('id');
        $metrics = app(PipelineMetricsService::class);
        $activeQuery = $metrics->scopeActivePipeline($metrics->workspaceQuery($workspace));

        $releasedQuery = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->where('status', 'completed');

        return [
            'total_active_leads' => (clone $activeQuery)->count(),
            'pending_verification' => WorkflowLead::whereIn('workflow_id', $workflowIds)->where('status', 'pending_verification')->count(),
            'tier_breakdown' => (clone $releasedQuery)
                ->select('tier', DB::raw('count(*) as total'))
                ->groupBy('tier')
                ->pluck('total', 'tier')
                ->all(),
            'stage_breakdown' => (clone $releasedQuery)
                ->select('pipeline_phase', DB::raw('count(*) as total'))
                ->groupBy('pipeline_phase')
                ->pluck('total', 'pipeline_phase')
                ->all(),
            'sdr_load' => $this->sdrLoad($workspace),
            'reactivation_queue' => app(LeadReactivationService::class)->candidates($workspace)->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function sdrLoad(Workspace $workspace): Collection
    {
        $cap = config('sales_ops.leads_per_sdr', 500);

        $members = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', SalesOps::sdrRoles())
            ->get();

        $memberIds = $members->pluck('id')->all();

        $assignedCounts = WorkflowLead::query()
            ->select('assigned_user_id', DB::raw('count(*) as total'))
            ->whereIn('assigned_user_id', $memberIds)
            ->where('status', 'completed')
            ->whereNotIn('stage', ['closed_won', 'closed_lost'])
            ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id')
            ->all();

        return $members
            ->map(function (User $member) use ($workspace, $cap, $assignedCounts) {
                $assigned = $assignedCounts[$member->id] ?? 0;

                return [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'assigned' => $assigned,
                    'cap' => $cap,
                    'available' => max(0, $cap - $assigned),
                    'at_capacity' => $assigned >= $cap,
                ];
            })
            ->sortByDesc('assigned')
            ->values();
    }

  /**
     * @return array<string, int>
     */
    protected function activityCounts(User $user, Workspace $workspace, Carbon $start, Carbon $end): array
    {
        $map = [
            'dial' => 'dial',
            'conversation' => 'conversation',
            'decision_maker' => 'decision_maker',
            'discovery' => 'discovery',
            'meeting_booked' => 'meeting_booked',
        ];

        $rows = LeadActivity::query()
            ->select('type', DB::raw('count(*) as total'))
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('type', array_keys($map))
            ->whereHas('lead.workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->groupBy('type')
            ->pluck('total', 'type');

        $counts = [];
        foreach ($map as $type => $key) {
            $counts[$key] = (int) ($rows[$type] ?? 0);
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<string, int>  $quotas
     * @return array{actual: int, target: int, pct: float}
     */
    protected function metric(string $quotaKey, array $counts, array $quotas): array
    {
        $typeMap = [
            'dials' => 'dial',
            'conversations' => 'conversation',
            'decision_maker_contacts' => 'decision_maker',
            'discoveries' => 'discovery',
        ];

        $actual = $counts[$typeMap[$quotaKey] ?? $quotaKey] ?? 0;
        $target = $quotas[$quotaKey] ?? 0;

        return [
            'actual' => $actual,
            'target' => $target,
            'pct' => $this->pct($actual, $target),
        ];
    }

    protected function pct(int $actual, int $target): float
    {
        if ($target <= 0) {
            return 100.0;
        }

        return min(100, round(($actual / $target) * 100, 1));
    }
}
