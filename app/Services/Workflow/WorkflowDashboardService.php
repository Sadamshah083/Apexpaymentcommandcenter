<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkflowLead;
use App\Models\LeadDisposition;
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
            ->with(['leadList:id,name', 'campaign:id,name'])
            ->withCount([
                'leads as assigned_leads_count' => fn ($query) => $query->whereNotNull('assigned_user_id'),
                'leads as enriched_leads_count' => fn ($query) => $query->enrichmentSucceeded(),
                'leads as ready_to_assign_count' => fn ($query) => $query->readyToAssign(),
            ])
            ->latest('workflows.id')
            ->paginate(config('pagination.workflows_per_page', 8), ['*'], 'pipelines_page')
            ->withQueryString();

        $this->attachAssignedAgentSummaries($workflows);
        $this->attachDispositionSummaries($workflows);

        if (! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }

        // Faster than pluck-all + whereIn when many files exist.
        $leadsQuery = WorkflowLead::query()
            ->select([
                'id',
                'workflow_id',
                'campaign_id',
                'business_name',
                'city',
                'state',
                'pipeline_phase',
                'direct_phone',
                'input_phone',
                'normalized_phone',
                'created_at',
            ])
            ->with([
                'workflow:id,name',
                'campaign:id,name',
            ])
            ->whereIn('workflow_id', $workspace->workflows()->select('id'));

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

        if (! empty($filters['workflow_ids']) && is_array($filters['workflow_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['workflow_ids'])));
            if ($ids !== []) {
                $leadsQuery->whereIn('workflow_id', $ids);
            }
        } elseif (! empty($filters['workflow_id'])) {
            $leadsQuery->where('workflow_id', (int) $filters['workflow_id']);
        }

        if (! empty($filters['assigned_user_id'])) {
            $userId = $filters['assigned_user_id'];
            $leadsQuery->where(function ($query) use ($userId) {
                $query->where('assigned_user_id', $userId)
                    ->orWhere('assigned_setter_id', $userId)
                    ->orWhere('assigned_closer_id', $userId);
            });
        }

        if (! empty($filters['assigned_only'])) {
            $leadsQuery->where(function ($query) {
                $query->whereNotNull('assigned_user_id')
                    ->orWhereNotNull('assigned_setter_id')
                    ->orWhereNotNull('assigned_closer_id');
            });
        }

        $assignableTeamLeads = WorkflowAssignmentRoles::assignableTeamLeadsFor($workspace);
        $assignableAgents = WorkflowAssignmentRoles::activeAssignableAgentsFor($workspace);
        $uploadedWorkflows = $workspace->workflows()
            ->orderByDesc('workflows.id')
            ->get(['workflows.id', 'workflows.name', 'workflows.original_filename', 'workflows.total_leads']);

        return [
            'workspace' => $workspace,
            'workflows' => $workflows,
            'uploadedWorkflows' => $uploadedWorkflows,
            'leads' => $leadsQuery->latest('id')->paginate(
                min(100, max(10, (int) ($filters['per_page'] ?? config('pagination.leads_per_page', 50)))),
                ['*'],
                'page'
            )->withQueryString(),
            'assignedLeadsView' => ! empty($filters['assigned_only']),
            'team' => $workspace->users()
                ->wherePivot('status', 'active')
                ->wherePivotIn('role', WorkflowAssignmentRoles::teamLeadRoles())
                ->orderBy('users.name')
                ->get(['users.id', 'users.name', 'users.email']),
            'setterTeamLeads' => $assignableTeamLeads,
            'activeSetters' => $assignableAgents,
            'setterTeamMemberMap' => WorkflowAssignmentRoles::assignableTeamMemberMap($workspace),
            'campaignNames' => $workspace->campaigns()->pluck('name', 'id'),
            'activeSetterCount' => $assignableAgents->count(),
            'pipelinePhases' => config('sales_ops.pipeline_phases', []),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                (bool) ($filters['refresh_enrichment'] ?? false),
                (bool) ($filters['refresh_enrichment'] ?? false)
            ),
        ];
    }

    /**
     * Attach per-agent assignment summaries onto each workflow on the current page.
     *
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\Workflow>  $workflows
     */
    protected function attachAssignedAgentSummaries($workflows): void
    {
        $ids = collect($workflows->items())->pluck('id')->filter()->values()->all();
        if ($ids === []) {
            return;
        }

        $rows = WorkflowLead::query()
            ->selectRaw('workflow_id, assigned_user_id, COUNT(*) as lead_count')
            ->whereIn('workflow_id', $ids)
            ->whereNotNull('assigned_user_id')
            ->groupBy('workflow_id', 'assigned_user_id')
            ->get();

        $names = User::query()
            ->whereIn('id', $rows->pluck('assigned_user_id')->unique()->filter()->all())
            ->pluck('name', 'id');

        $byWorkflow = [];
        foreach ($rows as $row) {
            $workflowId = (int) $row->workflow_id;
            $userId = (int) $row->assigned_user_id;
            $byWorkflow[$workflowId][] = [
                'user_id' => $userId,
                'name' => (string) ($names[$userId] ?? ('Agent #'.$userId)),
                'count' => (int) $row->lead_count,
            ];
        }

        foreach ($byWorkflow as $workflowId => $agents) {
            usort($agents, static fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']));
            $byWorkflow[$workflowId] = $agents;
        }

        foreach ($workflows->items() as $workflow) {
            $workflow->setAttribute('assigned_agents', $byWorkflow[(int) $workflow->id] ?? []);
        }
    }

    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection  $workflows
     */
    public function attachDispositionSummaries($workflows): void
    {
        $items = method_exists($workflows, 'items') ? $workflows->items() : $workflows;
        $ids = collect($items)->map(fn ($workflow) => (int) $workflow->id)->filter()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $rows = LeadDisposition::query()
            ->selectRaw('workflow_leads.workflow_id, lead_dispositions.disposition, COUNT(*) as disposition_count')
            ->join('workflow_leads', 'workflow_leads.id', '=', 'lead_dispositions.workflow_lead_id')
            ->whereIn('workflow_leads.workflow_id', $ids->all())
            ->whereNotNull('lead_dispositions.disposition')
            ->where('lead_dispositions.disposition', '!=', '')
            ->groupBy('workflow_leads.workflow_id', 'lead_dispositions.disposition')
            ->get();

        $byWorkflow = [];
        foreach ($rows as $row) {
            $workflowId = (int) $row->workflow_id;
            $label = trim((string) $row->disposition);
            $count = (int) $row->disposition_count;
            if ($label === '' || $count < 1) {
                continue;
            }
            $byWorkflow[$workflowId]['breakdown'][] = [
                'label' => $label,
                'count' => $count,
            ];
            $byWorkflow[$workflowId]['total'] = (int) (($byWorkflow[$workflowId]['total'] ?? 0) + $count);
        }

        foreach ($byWorkflow as $workflowId => $payload) {
            usort($payload['breakdown'], static fn (array $a, array $b) => $b['count'] <=> $a['count'] ?: strcmp($a['label'], $b['label']));
            $byWorkflow[$workflowId] = $payload;
        }

        foreach ($items as $workflow) {
            $summary = $byWorkflow[(int) $workflow->id] ?? ['total' => 0, 'breakdown' => []];
            $workflow->setAttribute('disposition_total', (int) ($summary['total'] ?? 0));
            $workflow->setAttribute('disposition_breakdown', array_values($summary['breakdown'] ?? []));
        }
    }

    /**
     * @return array{total: int, breakdown: list<array{label: string, count: int}>, rows: list<array<string, mixed>>}
     */
    public function dispositionHistoryForWorkflow(int $workflowId, int $limit = 200): array
    {
        $breakdownRows = LeadDisposition::query()
            ->selectRaw('lead_dispositions.disposition, COUNT(*) as disposition_count')
            ->join('workflow_leads', 'workflow_leads.id', '=', 'lead_dispositions.workflow_lead_id')
            ->where('workflow_leads.workflow_id', $workflowId)
            ->whereNotNull('lead_dispositions.disposition')
            ->where('lead_dispositions.disposition', '!=', '')
            ->groupBy('lead_dispositions.disposition')
            ->orderByDesc('disposition_count')
            ->get();

        $breakdown = $breakdownRows->map(fn ($row) => [
            'label' => (string) $row->disposition,
            'count' => (int) $row->disposition_count,
        ])->values()->all();

        $total = (int) collect($breakdown)->sum('count');

        $history = LeadDisposition::query()
            ->select([
                'lead_dispositions.id',
                'lead_dispositions.disposition',
                'lead_dispositions.phone',
                'lead_dispositions.note',
                'lead_dispositions.created_at',
                'lead_dispositions.user_id',
                'workflow_leads.business_name',
                'users.name as agent_name',
            ])
            ->join('workflow_leads', 'workflow_leads.id', '=', 'lead_dispositions.workflow_lead_id')
            ->leftJoin('users', 'users.id', '=', 'lead_dispositions.user_id')
            ->where('workflow_leads.workflow_id', $workflowId)
            ->whereNotNull('lead_dispositions.disposition')
            ->where('lead_dispositions.disposition', '!=', '')
            ->orderByDesc('lead_dispositions.created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'disposition' => (string) $row->disposition,
                'phone' => (string) ($row->phone ?: ''),
                'note' => (string) ($row->note ?: ''),
                'business_name' => (string) ($row->business_name ?: ''),
                'agent_name' => (string) ($row->agent_name ?: 'Agent'),
                'created_at' => optional($row->created_at)?->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'breakdown' => $breakdown,
            'rows' => $history,
        ];
    }
}
