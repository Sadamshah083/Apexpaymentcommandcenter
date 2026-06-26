<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use App\Services\SalesOps\SdrPerformanceService;
use App\Support\SalesOps;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WorkflowDashboardService
{
    public function __construct(
        protected WorkflowProviderStatusService $providerStatus,
        protected SdrPerformanceService $performance,
    ) {}

    /**
     * @return array<string, mixed>
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

        $isAdmin = $user->isWorkspaceAdmin($workspace->id);

        if ($user->isAccountExecutive() && ! $isAdmin) {
            $leadsQuery->where('status', 'completed')
                ->whereIn('stage', ['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost']);
        } elseif (! $isAdmin) {
            $leadsQuery->where('assigned_user_id', $user->id)
                ->where('status', 'completed');
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

        if (! empty($filters['tier'])) {
            $leadsQuery->where('tier', $filters['tier']);
        }

        $data = [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'leads' => $leadsQuery->orderBy('tier')->latest()->paginate(config('pagination.leads_per_page', 20))->withQueryString(),
            'team' => $workspace->users,
            'crmStages' => SalesOps::crmStages(),
            'leadTiers' => config('sales_ops.lead_tiers', []),
            'openRouterBalance' => $this->providerStatus->getOpenRouterBalance(),
            'geminiStatus' => $this->providerStatus->getGeminiStatus(),
        ];

        if ($user->isSdr() || $user->isMarketerOnly()) {
            $data['dailyMetrics'] = $this->performance->dailyMetrics($user, $workspace);
            $data['weeklyMetrics'] = $this->performance->weeklyMetrics($user, $workspace);
        }

        if ($isAdmin) {
            $data['salesOverview'] = $this->performance->workspaceOverview($workspace);
        }

        return $data;
    }
}
