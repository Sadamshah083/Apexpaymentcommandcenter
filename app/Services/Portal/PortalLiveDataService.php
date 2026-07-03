<?php

namespace App\Services\Portal;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Pipeline\RoleDashboardService;
use App\Services\Pipeline\SetterDistributionService;
use App\Services\Workspace\WorkspaceSyncService;

class PortalLiveDataService
{
    public function __construct(
        protected PortalDashboardService $dashboard,
        protected RoleDashboardService $roleDashboard,
        protected WorkspaceSyncService $sync,
        protected SetterDistributionService $setterDistribution,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function build(User $user, Workspace $workspace, array $context): array
    {
        $view = (string) ($context['view'] ?? '');
        $page = max(1, (int) ($context['page'] ?? 1));
        $filters = $this->filtersFromContext($context);

        $dashboard = $this->dashboard->forUser($user, $workspace);
        $payload = [
            'role' => $dashboard['role'] ?? null,
            'metrics' => $this->dashboard->flattenMetrics($dashboard),
        ];

        if (! empty($dashboard['leaderboard'])) {
            $payload['leaderboard'] = $dashboard['leaderboard'];
        }

        if (! empty($dashboard['upcoming'])) {
            $payload['upcoming'] = $this->serializeUpcoming($dashboard['upcoming']);
        }

        if (! empty($dashboard['setter_load'])) {
            $payload['setter_load'] = $dashboard['setter_load'];
        }

        if ($view === 'setter_team') {
            $payload['team_metrics'] = $this->formatSetterTeamMetrics(
                $this->roleDashboard->setterTeamMetrics($workspace)
            );
            $payload['unassigned_leads'] = $this->setterDistribution->unassignedLeadCount($workspace);
        }

        if ($view === 'closer_team') {
            $payload['team_metrics'] = $this->formatCloserTeamMetrics(
                $this->roleDashboard->closerTeamMetrics($workspace)
            );
        }

        if ($view !== '') {
            $leads = $this->roleDashboard->portalLeadsForPage($workspace, $user, $view, $filters, $page);
            $payload['leads'] = $this->sync->serializeLeadsCollection($leads);
            $payload['portal_view'] = $view;
            $payload['portal_page'] = $page;
        }

        return $payload;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function serializeUpcoming($items): array
    {
        return collect($items)
            ->map(fn (array $row) => [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? '',
                'when' => $row['when'] ?? null,
                'overdue' => (bool) ($row['overdue'] ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function filtersFromContext(array $context): array
    {
        return array_filter([
            'search' => $context['search'] ?? null,
            'phase' => $context['phase'] ?? null,
            'setter' => $context['setter'] ?? null,
            'closer' => $context['closer'] ?? null,
            'focus' => $context['focus'] ?? null,
            'tier' => $context['tier'] ?? null,
            'status' => $context['status'] ?? null,
            'member' => $context['member'] ?? null,
        ], fn ($value) => filled($value));
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @return list<array<string, mixed>>
     */
    protected function formatSetterTeamMetrics(array $metrics): array
    {
        return collect($metrics)
            ->map(fn (array $row) => [
                'user_id' => $row['user']->id,
                'name' => $row['user']->name,
                'active_leads' => (int) $row['active_leads'],
                'settled_leads' => (int) $row['settled_leads'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $metrics
     * @return list<array<string, mixed>>
     */
    protected function formatCloserTeamMetrics(array $metrics): array
    {
        return collect($metrics)
            ->map(fn (array $row) => [
                'user_id' => $row['user']->id,
                'name' => $row['user']->name,
                'active_leads' => (int) $row['active_leads'],
                'sales_made' => (int) $row['sales_made'],
                'total_closed' => (int) $row['total_closed'],
            ])
            ->values()
            ->all();
    }
}
