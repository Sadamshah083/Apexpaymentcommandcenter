<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use App\Support\WorkflowAssignmentRoles;

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
            ->with(['leadList', 'campaign'])
            ->withCount([
                'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                'leads as enriched_leads_count' => fn ($query) => $query->where('status', 'enriched'),
                'leads as ready_to_assign_count' => fn ($query) => $query->readyToAssign(),
            ])
            ->latest()
            ->paginate(config('pagination.workflows_per_page', 8), ['*'], 'pipelines_page')
            ->withQueryString();

        $workflowIds = $workspace->workflows()->pluck('id');

        $leadsQuery = WorkflowLead::query()
            ->with(['workflow', 'campaign', 'leadList'])
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

        if (! empty($filters['assigned_user_id'])) {
            $userId = $filters['assigned_user_id'];
            $leadsQuery->where(function ($query) use ($userId) {
                $query->where('assigned_user_id', $userId)
                    ->orWhere('assigned_setter_id', $userId)
                    ->orWhere('assigned_closer_id', $userId);
            });
        }

        return [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'leads' => $leadsQuery->latest()->paginate(config('pagination.leads_per_page', 20))->withQueryString(),
            'team' => $workspace->users()
                ->wherePivot('status', 'active')
                ->wherePivotIn('role', WorkflowAssignmentRoles::teamLeadRoles())
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'setterTeamLeads' => WorkflowAssignmentRoles::setterTeamLeadsFor($workspace),
            'activeSetters' => WorkflowAssignmentRoles::activeSettersFor($workspace),
            'setterTeamMemberMap' => WorkflowAssignmentRoles::setterTeamMemberMap($workspace),
            'activeSetterCount' => $workspace->users()
                ->wherePivot('role', 'appointment_setter')
                ->wherePivot('status', 'active')
                ->count(),
            'pipelinePhases' => config('sales_ops.pipeline_phases', []),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                (bool) ($filters['refresh_enrichment'] ?? false),
                (bool) ($filters['refresh_enrichment'] ?? false)
            ),
        ];
    }
}
