<?php

namespace App\Services\SalesOps;

use App\Models\LeadActivity;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
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

        $sdrIds = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', SalesOps::sdrRoles())
            ->pluck('users.id');

        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', array_merge(SalesOps::sdrRoles(), ['account_executive']))
            ->get()
            ->map(function (User $member) use ($workspace, $start, $end) {
                $counts = $this->activityCounts($member, $workspace, $start, $end);

                $funded = WorkflowLead::query()
                    ->where('assigned_user_id', $member->id)
                    ->where('stage', 'closed_won')
                    ->where('updated_at', '>=', $start)
                    ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                    ->count();

                return [
                    'user_id' => $member->id,
                    'name' => $member->name,
                    'role' => SalesOps::roleLabel($member->pivot->role ?? null),
                    'dials' => $counts['dial'] ?? 0,
                    'conversations' => $counts['conversation'] ?? 0,
                    'discoveries' => $counts['discovery'] ?? 0,
                    'meetings' => $counts['meeting_booked'] ?? 0,
                    'deals_funded' => $funded,
                    'score' => ($counts['discovery'] ?? 0) * 10 + ($counts['meeting_booked'] ?? 0) * 25 + $funded * 100,
                ];
            })
            ->sortByDesc('score')
            ->values();
    }

    public function workspaceOverview(Workspace $workspace): array
    {
        $workflowIds = $workspace->workflows()->pluck('id');

        $leadsQuery = WorkflowLead::query()->whereIn('workflow_id', $workflowIds)->where('status', 'completed');

        return [
            'total_active_leads' => (clone $leadsQuery)->whereNotIn('stage', ['closed_won', 'closed_lost'])->count(),
            'pending_verification' => WorkflowLead::whereIn('workflow_id', $workflowIds)->where('status', 'pending_verification')->count(),
            'tier_breakdown' => (clone $leadsQuery)
                ->select('tier', DB::raw('count(*) as total'))
                ->groupBy('tier')
                ->pluck('total', 'tier')
                ->all(),
            'stage_breakdown' => (clone $leadsQuery)
                ->select('stage', DB::raw('count(*) as total'))
                ->groupBy('stage')
                ->pluck('total', 'stage')
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

        return $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', SalesOps::sdrRoles())
            ->get()
            ->map(function (User $member) use ($workspace, $cap) {
                $assigned = WorkflowLead::query()
                    ->where('assigned_user_id', $member->id)
                    ->where('status', 'completed')
                    ->whereNotIn('stage', ['closed_won', 'closed_lost'])
                    ->whereHas('workflow', fn ($q) => $q->where('workspace_id', $workspace->id))
                    ->count();

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
