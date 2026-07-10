<?php

namespace App\Http\Controllers;

use App\Models\LeadCampaign;
use App\Services\Pipeline\CampaignBatchService;
use App\Services\Pipeline\CampaignService;
use App\Services\Workflow\WorkflowProviderStatusService;
use App\Services\Workspace\WorkspaceContextService;
use App\Support\WorkflowAssignmentRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CampaignController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected CampaignService $campaigns,
        protected CampaignBatchService $batchService,
        protected WorkflowProviderStatusService $providerStatus,
    ) {}

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        return view('campaigns.index', [
            'workspace' => $workspace,
            'campaigns' => $this->campaigns->campaignsWithStats($workspace),
        ]);
    }

    public function store(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $this->campaigns->create($workspace, Auth::user(), $data['name'], $data['description'] ?? null);

        return redirect()
            ->back()
            ->with('success', "Campaign \"{$data['name']}\" created.");
    }

    public function show(Request $request, LeadCampaign $campaign)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->ensureInWorkspace($campaign, $workspace);

        $workflowId = $request->filled('workflow_id') ? (int) $request->input('workflow_id') : null;
        $status = $request->input('status');

        return view('campaigns.show', [
            'workspace' => $workspace,
            'campaign' => $campaign,
            'campaigns' => $this->campaigns->listForWorkspace($workspace),
            'counts' => $this->batchService->countByStatus($workspace, $campaign->id, $workflowId),
            'leads' => $this->batchService->paginateLeads(
                $workspace,
                $campaign->id,
                $status ?: null,
                min(max((int) $request->input('per_page', 25), 5), 100),
                $workflowId,
            ),
            'workflows' => $campaign->workflows()->orderByDesc('created_at')->get(),
            'workflowId' => $workflowId,
            'status' => $status,
            'team' => $workspace->users()
                ->wherePivot('role', 'appointment_setter')
                ->wherePivot('status', 'active')
                ->get(),
            'setterTeamLeads' => WorkflowAssignmentRoles::setterTeamLeadsFor($workspace),
            'enrichmentConfigured' => $this->providerStatus->isEnrichmentConfigured(),
            'enrichmentConfigMessage' => $this->providerStatus->configurationMessage(),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                $request->boolean('refresh_enrichment')
            ),
        ]);
    }

    public function enrich(Request $request, LeadCampaign $campaign)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->ensureInWorkspace($campaign, $workspace);

        $count = $this->batchService->enrichByCampaign(
            $workspace,
            $campaign->id,
            $request->filled('workflow_id') ? (int) $request->input('workflow_id') : null,
        );

        return redirect()
            ->back()
            ->with('success', "Enrichment queued for {$count} leads.");
    }

    public function distribute(Request $request, LeadCampaign $campaign)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->ensureInWorkspace($campaign, $workspace);

        $request->validate([
            'distribution_users' => 'required|array|min:1',
            'distribution_users.*' => 'integer|exists:users,id',
        ]);

        $count = $this->batchService->distributeByCampaign(
            $workspace,
            Auth::user(),
            $campaign->id,
            array_map('intval', (array) $request->input('distribution_users', [])),
            $request->filled('workflow_id') ? (int) $request->input('workflow_id') : null,
        );

        return redirect()
            ->back()
            ->with('success', "{$count} leads distributed to appointment setters.");
    }

    public function assignTeamLead(Request $request, LeadCampaign $campaign)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->ensureInWorkspace($campaign, $workspace);

        $data = $request->validate([
            'team_lead_id' => 'required|integer|exists:users,id',
            'lead_count' => 'required|integer|min:1|max:5000',
            'workflow_id' => 'nullable|integer',
        ]);

        $count = $this->batchService->assignToTeamLead(
            $workspace,
            Auth::user(),
            $campaign->id,
            (int) $data['team_lead_id'],
            (int) $data['lead_count'],
            filled($data['workflow_id'] ?? null) ? (int) $data['workflow_id'] : null,
        );

        return redirect()
            ->back()
            ->with('success', "{$count} leads assigned to the team lead's setters.");
    }

    protected function ensureInWorkspace(LeadCampaign $campaign, $workspace): void
    {
        if ((int) $campaign->workspace_id !== (int) $workspace->id) {
            abort(404);
        }
    }
}
