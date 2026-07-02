<?php

namespace App\View\Composers;

use App\Services\Pipeline\RoleDashboardService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\View\View;

class PortalSidebarComposer
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected RoleDashboardService $dashboardService,
    ) {}

    public function compose(View $view): void
    {
        $user = auth()->user();
        if (! $user) {
            $view->with('portalTeamMembers', collect());

            return;
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $role = $user->getWorkspaceRole($workspace->id);

        $members = match ($role) {
            'appointment_setter_team_lead' => collect($this->dashboardService->setterTeamMetrics($workspace))
                ->map(fn (array $metric) => [
                    'name' => $metric['user']->name,
                    'href' => route('portal.setter-team.dashboard', ['setter' => $metric['user']->id]),
                    'count' => $metric['active_leads'],
                    'active' => request()->routeIs('portal.setter-team.*')
                        && (string) request('setter') === (string) $metric['user']->id,
                ])
                ->sortBy('name')
                ->values(),
            'closers_team_lead' => collect($this->dashboardService->closerTeamMetrics($workspace))
                ->map(fn (array $metric) => [
                    'name' => $metric['user']->name,
                    'href' => route('portal.closer-team.dashboard', ['closer' => $metric['user']->id]),
                    'count' => $metric['active_leads'],
                    'active' => request()->routeIs('portal.closer-team.dashboard')
                        && (string) request('closer') === (string) $metric['user']->id,
                ])
                ->sortBy('name')
                ->values(),
            default => collect(),
        };

        $view->with('portalTeamMembers', $members);
    }
}
