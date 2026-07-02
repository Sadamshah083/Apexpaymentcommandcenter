<?php

namespace App\Services\Workflow;

use App\Support\WorkflowAssignmentRoles;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;

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
        if (! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }

        $refreshEnrichment = (bool) ($filters['refresh_enrichment'] ?? false);

        $workflows = $workspace->workflows()
            ->select([
                'id',
                'workspace_id',
                'name',
                'original_filename',
                'status',
                'total_leads',
                'processed_leads',
                'enriched_leads',
                'failed_leads',
                'discarded_duplicates',
                'import_tag_ids',
                'lead_list_id',
                'created_at',
                'updated_at',
            ])
            ->withCount([
                'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                'leads as ready_to_assign_count' => fn ($query) => $query
                    ->where('status', 'enriched')
                    ->whereNull('assigned_user_id'),
            ])
            ->with('leadList:id,name')
            ->latest()
            ->paginate(config('pagination.workflows_per_page', 8), ['*'], 'pipelines_page')
            ->withQueryString();

        $leadsQuery = WorkflowLead::query()
            ->with([
                'tags:id,name,color',
                'leadList:id,name',
            ])
            ->whereIn('workflow_id', $workspace->workflows()->select('id'));

        if (empty($filters['phase'])) {
            $leadsQuery->where(function ($query) {
                $query->whereNotNull('assigned_user_id')
                    ->orWhereIn('pipeline_phase', ['with_setter', 'appointment_settled', 'with_closer', 'closed']);
            });
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

        if (! empty($filters['assigned_user_id'])) {
            $userId = $filters['assigned_user_id'];
            $leadsQuery->where(function ($query) use ($userId) {
                $query->where('assigned_user_id', $userId)
                    ->orWhere('assigned_setter_id', $userId)
                    ->orWhere('assigned_closer_id', $userId);
            });
        }

        if (($filters['include_leads'] ?? true) === false) {
            $leads = WorkflowLead::query()->whereRaw('0 = 1')->paginate(1);
        } else {
            $leads = $leadsQuery->latest('updated_at')->paginate(config('pagination.leads_per_page', 20))->withQueryString();
        }

        $dashboard = app(\App\Services\Pipeline\RoleDashboardService::class);

        return [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'leads' => $leads,
            'teamLeads' => WorkflowAssignmentRoles::teamLeadsFor($workspace),
            'setters' => $workspace->users()
                ->wherePivot('role', 'appointment_setter')
                ->wherePivot('status', 'active')
                ->orderBy('users.name')
                ->get(),
            'setterTeamMetrics' => $dashboard->setterTeamMetrics($workspace),
            'pipelinePhases' => config('sales_ops.pipeline_phases', []),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus($refreshEnrichment, $refreshEnrichment),
        ];
    }
}
