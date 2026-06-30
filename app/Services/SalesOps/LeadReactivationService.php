<?php

namespace App\Services\SalesOps;

use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class LeadReactivationService
{
    public function __construct(
        protected WorkspaceSyncService $syncService,
    ) {}

  /**
     * @return Collection<int, WorkflowLead>
     */
    public function candidates(Workspace $workspace, int $limit = 100): Collection
    {
        $workflowIds = $workspace->workflows()->pluck('id');

        return WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->where('status', 'completed')
            ->where(function ($query) {
                $query
                    ->whereIn('stage', ['follow_up', 'closed_lost', 'proposal_sent'])
                    ->orWhere(function ($q) {
                        $q->where('stage', 'meeting_scheduled')
                            ->where('schedule_at', '<', now()->subDays(1));
                    })
                    ->orWhere(function ($q) {
                        $q->where('stage', 'proposal_sent')
                            ->where('updated_at', '<', now()->subDays(14));
                    });
            })
            ->whereNull('reactivation_source')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();
    }

    public function enroll(WorkflowLead $lead, string $source): WorkflowLead
    {
        $allowed = array_keys(config('sales_ops.reactivation_sources', []));
        if (! in_array($source, $allowed, true)) {
            $source = 'old_lead';
        }

        $lead->update([
            'reactivation_source' => $source,
            'reactivation_eligible_at' => now(),
            'stage' => 'new_lead',
            'tier' => 'tier_1',
            'is_nurture' => false,
        ]);

        $lead = $lead->fresh();
        $workspace = $lead->workflow?->workspace;
        if ($workspace) {
            $this->syncService->record(
                $workspace,
                'lead.reactivated',
                'lead',
                $lead->id,
                ['source' => $source],
                Auth::id(),
            );
        }

        return $lead;
    }
}
