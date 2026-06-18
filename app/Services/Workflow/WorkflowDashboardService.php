<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkflowDashboardService
{
    public function __construct(
        protected WorkflowProviderStatusService $providerStatus,
    ) {}

    /**
     * @return array{
     *     workspace: Workspace,
     *     workflows: \Illuminate\Contracts\Pagination\LengthAwarePaginator,
     *     leads: LengthAwarePaginator,
     *     team: \Illuminate\Database\Eloquent\Collection,
     *     openRouterBalance: mixed,
     *     geminiStatus: string
     * }
     */
    public function buildIndexData(Workspace $workspace, User $user, array $filters = []): array
    {
        $workflows = $workspace->workflows()
            ->latest()
            ->paginate(config('pagination.workflows_per_page', 8), ['*'], 'pipelines_page')
            ->withQueryString();

        $workflowIds = $workspace->workflows()->pluck('id');

        $leadsQuery = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds);

        if ($workspace->admin_id !== $user->id) {
            $leadsQuery->where('assigned_user_id', $user->id);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $leadsQuery->where(function ($query) use ($search) {
                $query->where('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('direct_email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['stage'])) {
            $leadsQuery->where('stage', $filters['stage']);
        }

        return [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'leads' => $leadsQuery->latest()->paginate(config('pagination.leads_per_page', 20))->withQueryString(),
            'team' => $workspace->users,
            'openRouterBalance' => $this->providerStatus->getOpenRouterBalance(),
            'geminiStatus' => $this->providerStatus->getGeminiStatus(),
        ];
    }
}
