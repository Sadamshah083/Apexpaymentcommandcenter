<?php

namespace App\Services\Pipeline;

use App\Models\LeadCampaign;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public function listForWorkspace(Workspace $workspace): Collection
    {
        return LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function create(Workspace $workspace, User $user, string $name, ?string $description = null): LeadCampaign
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['campaign_name' => 'Campaign name is required.']);
        }

        if (LeadCampaign::query()->where('workspace_id', $workspace->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['campaign_name' => 'A campaign with this name already exists.']);
        }

        return LeadCampaign::create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'description' => $description,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function update(LeadCampaign $campaign, string $name, ?string $description = null): LeadCampaign
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Campaign name is required.']);
        }

        $duplicate = LeadCampaign::query()
            ->where('workspace_id', $campaign->workspace_id)
            ->where('name', $name)
            ->where('id', '!=', $campaign->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'A campaign with this name already exists.']);
        }

        $campaign->update([
            'name' => $name,
            'description' => $description,
        ]);

        return $campaign->fresh();
    }

    public function delete(LeadCampaign $campaign): void
    {
        $campaign->delete();
    }

    public function resolveForImport(Workspace $workspace, User $user, ?int $campaignId, ?string $campaignName): LeadCampaign
    {
        if ($campaignId) {
            $campaign = LeadCampaign::query()
                ->where('workspace_id', $workspace->id)
                ->where('id', $campaignId)
                ->first();

            if (! $campaign) {
                throw ValidationException::withMessages(['campaign_id' => 'Selected campaign was not found.']);
            }

            return $campaign;
        }

        if (filled($campaignName)) {
            return $this->create($workspace, $user, $campaignName);
        }

        throw ValidationException::withMessages([
            'campaign_id' => 'Select an existing campaign or enter a name for a new one.',
        ]);
    }

    /**
     * @return Collection<int, LeadCampaign>
     */
    public function campaignsWithStats(Workspace $workspace): Collection
    {
        return LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount([
                'leads as leads_count',
                'leads as imported_count' => fn ($q) => $q->where('workflow_leads.status', 'imported'),
                'leads as enriched_count' => fn ($q) => $q->where('workflow_leads.status', 'enriched'),
                'leads as assigned_count' => fn ($q) => $q->whereNotNull('workflow_leads.assigned_user_id'),
                'leads as failed_count' => fn ($q) => $q->where('workflow_leads.status', 'failed'),
                'workflows as imports_count',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Campaign summaries for portal team lead dashboards.
     *
     * @return Collection<int, LeadCampaign>
     */
    public function portalSummaries(Workspace $workspace): Collection
    {
        return LeadCampaign::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->withCount([
                'leads as leads_count',
                'leads as ready_count' => fn ($q) => $q
                    ->where('workflow_leads.status', 'enriched')
                    ->whereNull('workflow_leads.assigned_user_id'),
                'leads as active_setter_count' => fn ($q) => $q
                    ->where('workflow_leads.pipeline_phase', 'with_setter'),
                'leads as handoff_count' => fn ($q) => $q
                    ->where('workflow_leads.pipeline_phase', 'appointment_settled')
                    ->whereNull('workflow_leads.assigned_closer_id'),
                'leads as active_closer_count' => fn ($q) => $q
                    ->where('workflow_leads.pipeline_phase', 'with_closer'),
                'workflows as imports_count',
            ])
            ->whereHas('leads')
            ->orderByDesc('leads_count')
            ->get();
    }
}
