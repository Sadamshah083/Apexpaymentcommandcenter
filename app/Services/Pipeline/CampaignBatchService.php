<?php

namespace App\Services\Pipeline;

use App\Jobs\ProcessLeadJob;
use App\Models\LeadCampaign;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CampaignBatchService
{
    public function __construct(
        protected PipelineLeadReleaseService $releaseService,
        protected WorkflowProviderStatusService $providerStatus,
        protected SetterDistributionService $setterDistribution,
    ) {}

    public function paginateLeads(
        Workspace $workspace,
        int $campaignId,
        ?string $status = null,
        int $perPage = 25,
        ?int $workflowId = null,
    ): LengthAwarePaginator {
        return $this->baseLeadQuery($workspace, $campaignId, $status, $workflowId)
            ->with(['workflow', 'campaign'])
            ->orderByDesc('workflow_leads.created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @return array{imported: int, enriched: int, ready_to_distribute: int, failed: int, assigned: int, total: int}
     */
    public function countByStatus(Workspace $workspace, int $campaignId, ?int $workflowId = null): array
    {
        $base = $this->baseLeadQuery($workspace, $campaignId, null, $workflowId);

        return [
            'total' => (clone $base)->count(),
            'imported' => (clone $base)->where('workflow_leads.status', 'imported')->count(),
            'enriched' => (clone $base)->where('workflow_leads.status', 'enriched')->count(),
            'ready_to_distribute' => (clone $base)
                ->where('workflow_leads.status', 'enriched')
                ->whereNull('workflow_leads.assigned_user_id')
                ->count(),
            'failed' => (clone $base)->where('workflow_leads.status', 'failed')->count(),
            'assigned' => (clone $base)->whereNotNull('workflow_leads.assigned_user_id')->count(),
        ];
    }

    public function enrichByCampaign(Workspace $workspace, int $campaignId, ?int $workflowId = null): int
    {
        if (! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        $this->ensureCampaignInWorkspace($workspace, $campaignId);

        $leads = $this->baseLeadQuery($workspace, $campaignId, null, $workflowId)
            ->whereIn('workflow_leads.status', ['imported', 'failed'])
            ->with('workflow')
            ->orderBy('workflow_leads.row_number')
            ->get();

        if ($leads->isEmpty()) {
            throw ValidationException::withMessages([
                'campaign' => 'No imported or failed leads in this campaign.',
            ]);
        }

        $dispatched = 0;
        foreach ($leads as $lead) {
            if ($lead->status === 'failed') {
                $lead->update(['status' => 'imported', 'error_message' => null]);
                $lead->workflow?->decrement('failed_leads');
            }

            ProcessLeadJob::dispatch($lead->id, $lead->workflow?->custom_prompt);
            $dispatched++;
        }

        return $dispatched;
    }

    public function distributeByCampaign(
        Workspace $workspace,
        User $actor,
        int $campaignId,
        array $distributionUsers,
        ?int $workflowId = null,
    ): int {
        $this->ensureCampaignInWorkspace($workspace, $campaignId);

        if ($distributionUsers === []) {
            throw ValidationException::withMessages([
                'distribution_users' => 'Select at least one appointment setter.',
            ]);
        }

        $leads = $this->baseLeadQuery($workspace, $campaignId, 'enriched', $workflowId)
            ->whereNull('workflow_leads.assigned_user_id')
            ->orderBy('workflow_leads.row_number')
            ->get();

        if ($leads->isEmpty()) {
            throw ValidationException::withMessages([
                'campaign' => 'No enriched, unassigned leads in this campaign.',
            ]);
        }

        $count = 0;
        foreach ($leads as $lead) {
            $this->releaseService->releaseToSetter($lead, $actor, $distributionUsers);
            $count++;
        }

        return $count;
    }

    public function assignToTeamLead(
        Workspace $workspace,
        User $actor,
        int $campaignId,
        int $teamLeadId,
        int $leadCount,
        ?int $workflowId = null,
    ): int {
        $this->ensureCampaignInWorkspace($workspace, $campaignId);

        $teamLead = $workspace->users()
            ->where('users.id', $teamLeadId)
            ->wherePivot('status', 'active')
            ->first();

        if (! $teamLead) {
            throw ValidationException::withMessages([
                'team_lead_id' => 'Selected team lead is not active in this workspace.',
            ]);
        }

        $assigned = $this->setterDistribution->assignCampaignLeadsToTeamLead(
            $workspace,
            $campaignId,
            $teamLead,
            $leadCount,
            $actor,
            $workflowId,
        );

        if ($assigned === 0) {
            throw ValidationException::withMessages([
                'lead_count' => 'No enriched unassigned leads available, or no active setters on the team.',
            ]);
        }

        return $assigned;
    }

    protected function baseLeadQuery(
        Workspace $workspace,
        int $campaignId,
        ?string $status = null,
        ?int $workflowId = null,
    ): Builder {
        $query = WorkflowLead::query()
            ->select('workflow_leads.*')
            ->where('campaign_id', $campaignId)
            ->whereHas('workflow', fn (Builder $q) => $q->where('workspace_id', $workspace->id));

        if ($workflowId) {
            $query->where('workflow_leads.workflow_id', $workflowId);
        }

        if ($status !== null && $status !== '') {
            $query->where('workflow_leads.status', $status);
        }

        return $query;
    }

    protected function ensureCampaignInWorkspace(Workspace $workspace, int $campaignId): LeadCampaign
    {
        $campaign = LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $campaignId)
            ->first();

        if (! $campaign) {
            throw ValidationException::withMessages([
                'campaign_id' => 'Campaign not found in this workspace.',
            ]);
        }

        return $campaign;
    }
}
