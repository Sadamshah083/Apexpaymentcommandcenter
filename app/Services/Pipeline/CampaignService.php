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
}
