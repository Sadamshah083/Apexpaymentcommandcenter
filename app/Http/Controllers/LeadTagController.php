<?php

namespace App\Http\Controllers;

use App\Models\LeadList;
use App\Models\LeadTag;
use App\Services\Pipeline\LeadSegmentationService;
use App\Services\Pipeline\LeadTagBatchService;
use App\Services\Workflow\WorkflowProviderStatusService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeadTagController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected LeadTagBatchService $batchService,
        protected LeadSegmentationService $segmentation,
        protected WorkflowProviderStatusService $providerStatus,
    ) {}

    public function index(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $tags = $this->batchService->tagsWithStats($workspace);

        return view('lead-tags.index', [
            'workspace' => $workspace,
            'tags' => $tags,
        ]);
    }

    public function show(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $tagIds = array_map('intval', (array) $request->input('tag_ids', []));
        if ($tagIds === [] && $request->filled('tag')) {
            $tagIds = [(int) $request->input('tag')];
        }

        $match = $request->input('match', 'any') === 'all' ? 'all' : 'any';
        $status = $request->input('status');
        $listIds = array_map('intval', (array) $request->input('list_ids', []));

        $counts = $this->batchService->countByStatus($workspace, $tagIds, $match, $listIds);
        $leads = $this->batchService->paginateLeads(
            $workspace,
            $tagIds,
            $match,
            $status ?: null,
            $listIds,
            min(max((int) $request->input('per_page', 25), 5), 100),
        );

        $selectedTags = LeadTag::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $tagIds)
            ->orderBy('name')
            ->get();

        return view('lead-tags.show', [
            'workspace' => $workspace,
            'tags' => $this->batchService->tagsWithStats($workspace),
            'selectedTags' => $selectedTags,
            'selectedTagIds' => $tagIds,
            'match' => $match,
            'status' => $status,
            'listIds' => $listIds,
            'counts' => $counts,
            'leads' => $leads,
            'lists' => LeadList::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'team' => $workspace->users()
                ->wherePivot('role', 'appointment_setter')
                ->wherePivot('status', 'active')
                ->get(),
            'enrichmentConfigured' => $this->providerStatus->isEnrichmentConfigured(),
            'enrichmentConfigMessage' => $this->providerStatus->configurationMessage(),
            'enrichmentStatus' => $this->providerStatus->getEnrichmentStatus(
                $request->boolean('refresh_enrichment')
            ),
        ]);
    }

    public function enrich(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->validateTagRequest($request);

        $count = $this->batchService->enrichByTags(
            $workspace,
            (array) $request->input('tag_ids', []),
            $request->input('match', 'any') === 'all' ? 'all' : 'any',
            (array) $request->input('list_ids', []),
        );

        return redirect()
            ->to($this->redirectUrl($request))
            ->with('success', "Enrichment queued for {$count} leads across all matching imports.");
    }

    public function distribute(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->validateTagRequest($request);

        $request->validate([
            'distribution_users' => 'required|array|min:1',
            'distribution_users.*' => 'integer|exists:users,id',
        ]);

        $count = $this->batchService->distributeByTags(
            $workspace,
            Auth::user(),
            (array) $request->input('tag_ids', []),
            array_map('intval', (array) $request->input('distribution_users', [])),
            $request->input('match', 'any') === 'all' ? 'all' : 'any',
            (array) $request->input('list_ids', []),
        );

        return redirect()
            ->to($this->redirectUrl($request))
            ->with('success', "{$count} enriched leads distributed to appointment setters.");
    }

    public function applyTags(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $request->validate([
            'tag_ids' => 'required|array|min:1',
            'tag_ids.*' => 'integer',
            'apply_tag_ids' => 'required|array|min:1',
            'apply_tag_ids.*' => 'integer',
            'lead_ids' => 'nullable|array',
            'lead_ids.*' => 'integer',
        ]);

        $count = $this->batchService->applyTagsToMatchingLeads(
            $workspace,
            (array) $request->input('apply_tag_ids', []),
            (array) $request->input('lead_ids', []),
            (array) $request->input('tag_ids', []),
            $request->input('match', 'any') === 'all' ? 'all' : 'any',
            (array) $request->input('list_ids', []),
        );

        return redirect()
            ->to($this->redirectUrl($request))
            ->with('success', "Tag applied to {$count} leads.");
    }

    protected function validateTagRequest(Request $request): void
    {
        $request->validate([
            'tag_ids' => 'required|array|min:1',
            'tag_ids.*' => 'integer',
            'match' => 'nullable|in:any,all',
            'list_ids' => 'nullable|array',
            'list_ids.*' => 'integer',
        ]);
    }

    protected function redirectUrl(Request $request): string
    {
        $params = array_filter([
            'tag_ids' => $request->input('tag_ids'),
            'match' => $request->input('match'),
            'status' => $request->input('status'),
            'list_ids' => $request->input('list_ids'),
        ], fn ($value) => $value !== null && $value !== []);

        return route('admin.lead-tags.show', $params);
    }
}
