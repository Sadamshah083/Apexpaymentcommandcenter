<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use App\Support\SalesOps;

class WorkflowDashboardService
{
    public function __construct(
        protected WorkflowProviderStatusService $providerStatus,
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
            ->with('workflow')
            ->whereIn('workflow_id', $workflowIds);

        if (! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $leadsQuery->where(function ($query) use ($search) {
                $query->where('business_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('direct_email', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['phase'])) {
            $leadsQuery->where('pipeline_phase', $filters['phase']);
        }

        return [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'leads' => $leadsQuery->latest()->paginate(config('pagination.leads_per_page', 20))->withQueryString(),
            'team' => $workspace->users,
            'pipelinePhases' => config('sales_ops.pipeline_phases', []),
            'openRouterBalance' => $this->providerStatus->getOpenRouterBalance(),
            'geminiStatus' => $this->providerStatus->getGeminiStatus(),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                (bool) ($filters['refresh_enrichment'] ?? false)
            ),
            'phaseCounts' => WorkflowLead::query()
                ->whereIn('workflow_id', $workflowIds)
                ->selectRaw('pipeline_phase, count(*) as total')
                ->groupBy('pipeline_phase')
                ->pluck('total', 'pipeline_phase'),
        ];
    }
}
