<?php

namespace App\Services\Pipeline;

use App\Jobs\ProcessLeadJob;
use App\Models\LeadTag;
use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workflow\WorkflowProviderStatusService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LeadTagBatchService
{
    public function __construct(
        protected PipelineLeadReleaseService $releaseService,
        protected LeadSegmentationService $segmentation,
        protected WorkflowProviderStatusService $providerStatus,
    ) {}

    /**
     * @return Collection<int, LeadTag>
     */
    public function tagsWithStats(Workspace $workspace): Collection
    {
        return LeadTag::query()
            ->where('workspace_id', $workspace->id)
            ->withCount([
                'leads as leads_count' => fn (Builder $q) => $this->scopeLeadsToWorkspace($q, $workspace->id),
                'leads as imported_count' => fn (Builder $q) => $this->scopeLeadsToWorkspace($q, $workspace->id)
                    ->where('workflow_leads.status', 'imported'),
                'leads as enriched_count' => fn (Builder $q) => $this->scopeLeadsToWorkspace($q, $workspace->id)
                    ->where('workflow_leads.status', 'enriched'),
                'leads as assigned_count' => fn (Builder $q) => $this->scopeLeadsToWorkspace($q, $workspace->id)
                    ->whereNotNull('workflow_leads.assigned_user_id'),
                'leads as failed_count' => fn (Builder $q) => $this->scopeLeadsToWorkspace($q, $workspace->id)
                    ->where('workflow_leads.status', 'failed'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>  $listIds
     */
    public function paginateLeads(
        Workspace $workspace,
        array $tagIds,
        string $match = 'any',
        ?string $status = null,
        array $listIds = [],
        int $perPage = 25,
    ): LengthAwarePaginator {
        return $this->baseLeadQuery($workspace, $tagIds, $match, $status, $listIds)
            ->with(['tags', 'workflow', 'leadList'])
            ->orderByDesc('workflow_leads.created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  array<int, int>  $tagIds
     * @return array{imported: int, enriched: int, ready_to_distribute: int, failed: int, assigned: int, total: int}
     */
    public function countByStatus(Workspace $workspace, array $tagIds, string $match = 'any', array $listIds = []): array
    {
        $base = $this->baseLeadQuery($workspace, $tagIds, $match, null, $listIds);

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

    /**
     * @param  array<int, int>  $tagIds
     */
    public function enrichByTags(Workspace $workspace, array $tagIds, string $match = 'any', array $listIds = []): int
    {
        if (! $this->providerStatus->isEnrichmentConfigured()) {
            throw ValidationException::withMessages([
                'enrichment' => $this->providerStatus->configurationMessage(),
            ]);
        }

        $tagIds = $this->normalizeIds($tagIds);
        if ($tagIds === []) {
            throw ValidationException::withMessages([
                'tag_ids' => 'Select at least one tag.',
            ]);
        }

        $leads = $this->baseLeadQuery($workspace, $tagIds, $match, null, $listIds)
            ->whereIn('workflow_leads.status', ['imported', 'failed'])
            ->with('workflow')
            ->orderBy('workflow_leads.row_number')
            ->get();

        if ($leads->isEmpty()) {
            throw ValidationException::withMessages([
                'tags' => 'No imported or failed leads match the selected tags.',
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

    /**
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>|null  $distributionUsers
     */
    public function distributeByTags(
        Workspace $workspace,
        User $actor,
        array $tagIds,
        ?array $distributionUsers = null,
        string $match = 'any',
        array $listIds = [],
    ): int {
        $tagIds = $this->normalizeIds($tagIds);
        if ($tagIds === []) {
            throw ValidationException::withMessages([
                'tag_ids' => 'Select at least one tag.',
            ]);
        }

        if ($distributionUsers !== null && $distributionUsers === []) {
            throw ValidationException::withMessages([
                'distribution_users' => 'Select at least one appointment setter.',
            ]);
        }

        $leads = $this->baseLeadQuery($workspace, $tagIds, $match, 'enriched', $listIds)
            ->whereNull('workflow_leads.assigned_user_id')
            ->orderBy('workflow_leads.row_number')
            ->get();

        if ($leads->isEmpty()) {
            throw ValidationException::withMessages([
                'tags' => 'No enriched, unassigned leads match the selected tags.',
            ]);
        }

        $count = 0;
        foreach ($leads as $lead) {
            $this->releaseService->releaseToSetter($lead, $actor, $distributionUsers);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>  $leadIds
     */
    public function applyTagsToMatchingLeads(
        Workspace $workspace,
        array $tagIds,
        array $leadIds,
        array $filterTagIds = [],
        string $match = 'any',
        array $listIds = [],
    ): int {
        $tagIds = $this->normalizeIds($tagIds);
        if ($tagIds === []) {
            throw ValidationException::withMessages([
                'apply_tag_ids' => 'Select at least one tag to apply.',
            ]);
        }

        $this->ensureTagsBelongToWorkspace($workspace, $tagIds);

        $query = $this->baseLeadQuery(
            $workspace,
            $filterTagIds !== [] ? $filterTagIds : $tagIds,
            $match,
            null,
            $listIds,
        );

        if ($leadIds !== []) {
            $query->whereIn('workflow_leads.id', $this->normalizeIds($leadIds));
        }

        $ids = $query->pluck('workflow_leads.id')->all();
        $this->segmentation->attachTagsToLeads($ids, $tagIds);

        return count($ids);
    }

    /**
     * @param  array<int, int>  $tagIds
     * @param  array<int, int>  $listIds
     */
    protected function baseLeadQuery(
        Workspace $workspace,
        array $tagIds,
        string $match = 'any',
        ?string $status = null,
        array $listIds = [],
    ): Builder {
        $tagIds = $this->normalizeIds($tagIds);

        $query = WorkflowLead::query()
            ->select('workflow_leads.*')
            ->whereHas('workflow', fn (Builder $q) => $q->where('workspace_id', $workspace->id));

        if ($tagIds !== []) {
            $this->applyTagFilter($query, $tagIds, $match);
        }

        if ($status !== null && $status !== '') {
            $query->where('workflow_leads.status', $status);
        }

        $listIds = $this->normalizeIds($listIds);
        if ($listIds !== []) {
            $query->whereIn('workflow_leads.lead_list_id', $listIds);
        }

        return $query;
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    protected function applyTagFilter(Builder $query, array $tagIds, string $match): void
    {
        if ($match === 'all') {
            foreach ($tagIds as $tagId) {
                $query->whereHas('tags', fn (Builder $q) => $q->where('lead_tags.id', $tagId));
            }

            return;
        }

        $query->whereHas('tags', fn (Builder $q) => $q->whereIn('lead_tags.id', $tagIds));
    }

    protected function scopeLeadsToWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->whereHas('workflow', fn (Builder $q) => $q->where('workspace_id', $workspaceId));
    }

    /**
     * @param  array<int, int>  $tagIds
     */
    protected function ensureTagsBelongToWorkspace(Workspace $workspace, array $tagIds): void
    {
        $valid = LeadTag::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $tagIds)
            ->count();

        if ($valid !== count($tagIds)) {
            throw ValidationException::withMessages([
                'tag_ids' => 'One or more tags are invalid for this workspace.',
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    protected function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
