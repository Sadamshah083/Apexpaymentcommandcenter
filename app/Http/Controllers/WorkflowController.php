<?php

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceManager;
use App\Services\Workspace\WorkspaceMemberService;
use App\Services\Pipeline\LeadPipelineService;
use App\Services\Pipeline\SetterDistributionService;
use App\Services\Pipeline\CampaignService;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowLeadService;
use App\Services\Workflow\WorkflowLeadVerificationService;
use App\Services\Workflow\WorkflowService;
use App\Services\SalesOps\DiscoveryQualificationService;
use App\Support\LeadRoute;
use App\Support\SalesOps;
use App\Support\WorkflowAssignmentRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorkflowController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected WorkspaceManager $workspaceManager,
        protected WorkspaceMemberService $memberService,
        protected WorkflowDashboardService $dashboardService,
        protected WorkflowService $workflowService,
        protected WorkflowLeadService $leadService,
        protected WorkflowLeadVerificationService $verificationService,
        protected DiscoveryQualificationService $discoveryService,
        protected LeadPipelineService $pipelineService,
        protected SetterDistributionService $setterDistribution,
        protected CampaignService $campaignService,
    ) {}

    public function assignedLeads(Request $request)
    {
        $request->merge(['view' => 'assigned']);

        return $this->index($request);
    }

    public function index(Request $request)
    {
        if ($request->is('admin*') || $request->routeIs('admin.*')) {
            if ($request->routeIs('admin.workflows.index') && $request->input('view') === 'assigned') {
                return redirect()->route('admin.assigned-leads', $request->except('view'));
            }

            $user = Auth::user();
            $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
            $assignedLeadsView = $request->routeIs('admin.assigned-leads')
                || $request->input('view') === 'assigned';
            $data = $this->dashboardService->buildIndexData($workspace, $user, [
                'search' => $request->input('search'),
                'phase' => $request->input('phase'),
                'workflow_id' => $request->input('workflow_id'),
                'workflow_ids' => $request->input('workflow_ids', []),
                'assigned_user_id' => $request->input('assigned_user_id'),
                'assigned_only' => $assignedLeadsView,
                'per_page' => $request->input('per_page'),
                'refresh_enrichment' => $request->boolean('refresh_enrichment'),
            ]);

            $data['importsWorkflows'] = $data['workflows'];
            $data['campaigns'] = $this->campaignService->campaignsWithStats($workspace);
            $data['assignedLeadsView'] = $assignedLeadsView;

            // Use denormalized workflow counters — never GROUP BY all leads on every page click
            // (that was hanging /admin/workflows?page=N with 3k+ leads).
            $perPage = (int) config('pagination.workflows_per_page', 20);
            $summaryWorkflows = $workspace->workflows()
                ->latest()
                ->paginate($perPage, [
                    'id',
                    'name',
                    'original_filename',
                    'created_at',
                    'total_leads',
                    'enriched_leads',
                    'failed_leads',
                ], 'files_page')
                ->withQueryString();

            $summaryIds = $summaryWorkflows->getCollection()->modelKeys();
            $closedByWorkflow = $summaryIds === []
                ? collect()
                : WorkflowLead::query()
                    ->selectRaw('workflow_id, COUNT(*) as closed_count')
                    ->whereIn('workflow_id', $summaryIds)
                    ->where(function ($query) {
                        $query->where(function ($inner) {
                            $inner->where('pipeline_phase', 'closed')
                                ->where('closer_status', 'sale_made');
                        })->orWhere('stage', 'closed_won');
                    })
                    ->groupBy('workflow_id')
                    ->pluck('closed_count', 'workflow_id');

            $data['workflowSummaries'] = $summaryWorkflows
                ->getCollection()
                ->map(function (Workflow $workflow) use ($closedByWorkflow) {
                    $totalLeadsCount = (int) ($workflow->total_leads ?? 0);
                    $enrichedCount = (int) ($workflow->enriched_leads ?? 0);
                    $closedCount = (int) ($closedByWorkflow[$workflow->id] ?? 0);

                    return [
                        'id' => $workflow->id,
                        'name' => $workflow->name,
                        'filename' => $workflow->original_filename,
                        'created_at' => $workflow->created_at ? $workflow->created_at->toDateString() : '',
                        'total_leads' => $totalLeadsCount,
                        'enriched_leads' => $enrichedCount,
                        'failed_leads' => (int) ($workflow->failed_leads ?? 0),
                        'closed_deals' => $closedCount,
                        'enrichment_rate' => $totalLeadsCount > 0 ? round(($enrichedCount / $totalLeadsCount) * 100, 1) : 0,
                        'close_rate' => $totalLeadsCount > 0 ? round(($closedCount / $totalLeadsCount) * 100, 1) : 0,
                    ];
                })
                ->all();
            $data['workflowSummariesPaginator'] = $summaryWorkflows;
            $data['activeSection'] = 'imports';

            return view('workflows.index', $data);
        }

        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        return view('workflows.index', $this->dashboardService->buildIndexData($workspace, $user, [
            'search' => $request->input('search'),
            'stage' => $request->input('stage'),
            'phase' => $request->input('phase'),
            'assigned_user_id' => $request->input('assigned_user_id'),
            'refresh_enrichment' => $request->boolean('refresh_enrichment'),
        ]));
    }

    public function create()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        return view('workflows.create', [
            'workspace' => $workspace,
            'campaigns' => $this->campaignService->listForWorkspace($workspace),
        ]);
    }

    public function store(Request $request)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $request->validate([
            'name' => 'required|string|max:255',
            // Extension-based check — browser MIME for Excel is often wrong / octet-stream.
            'file' => ['required', 'file', 'max:51200', function (string $attribute, $value, \Closure $fail) {
                $ext = strtolower((string) $value->getClientOriginalExtension());
                if (! in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) {
                    $fail('Upload a CSV or Excel file (.csv, .xlsx, .xls).');
                }
            }],
            'processing_mode' => 'required|in:store_only,full_pipeline,import_only,import_and_enrich',
            'campaign_id' => 'nullable|integer',
            'campaign_name' => 'nullable|string|max:100',
            'import_segment' => 'nullable|string|max:120',
            'import_tags' => 'nullable|string|max:255',
        ], [
            'file.required' => 'Choose a spreadsheet file to upload.',
            'file.max' => 'The file may not be larger than 50 MB.',
            'name.required' => 'Enter an import file name.',
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $campaign = $this->campaignService->resolveForImport(
            $workspace,
            Auth::user(),
            $request->filled('campaign_id') ? (int) $request->input('campaign_id') : null,
            $request->input('campaign_name'),
        );

        $importTags = collect(preg_split('/[,;]+/', (string) $request->input('import_tags', '')))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique()
            ->take(12)
            ->values()
            ->all();

        try {
            $workflow = $this->workflowService->createFromUpload(
                $workspace,
                $request->input('name'),
                $request->file('file'),
                $request->input('processing_mode'),
                $campaign->id,
                $importTags,
                filled($request->input('import_segment')) ? trim((string) $request->input('import_segment')) : null,
            );
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->back()
                ->withInput()
                ->withErrors(['file' => 'Could not store the upload: '.$e->getMessage()]);
        }

        $workflow = $this->workflowService->applyAutoMappingIfNeeded($workflow);

        $mappedBusiness = $workflow->fresh()->column_mapping['business_name'] ?? null;
        $message = $mappedBusiness
            ? "Spreadsheet uploaded. AI mapped \"{$mappedBusiness}\" to Business Name."
            : 'Spreadsheet uploaded. Review the column mapping, then run the pipeline.';

        return redirect()
            ->route('admin.workflows.show', $workflow->id)
            ->with('success', $message);
    }

    public function update(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'original_filename' => 'nullable|string|max:255',
            'agent_restricted' => 'sometimes|boolean',
        ]);

        if ($request->exists('agent_restricted') && ! $request->filled('name') && ! $request->exists('original_filename')) {
            $workflow->agent_restricted = $request->boolean('agent_restricted');
            $workflow->save();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'ok' => true,
                    'id' => $workflow->id,
                    'agent_restricted' => (bool) $workflow->agent_restricted,
                    'message' => $workflow->agent_restricted
                        ? 'File restricted from agent dialer.'
                        : 'File visible to agents again.',
                ]);
            }

            return redirect()
                ->back()
                ->with('success', $workflow->agent_restricted
                    ? 'File restricted from agent dialer.'
                    : 'File visible to agents again.');
        }

        $name = trim((string) ($data['name'] ?? $workflow->name));
        $filename = array_key_exists('original_filename', $data)
            ? trim((string) ($data['original_filename'] ?? ''))
            : null;

        $workflow->name = $name;
        if ($filename !== null && $filename !== '') {
            // Keep a spreadsheet-looking name when editing display file label.
            if (! preg_match('/\.(csv|txt|xlsx|xls)$/i', $filename)) {
                $ext = pathinfo((string) $workflow->original_filename, PATHINFO_EXTENSION) ?: 'xlsx';
                $filename .= '.'.$ext;
            }
            $workflow->original_filename = $filename;
        }
        if ($request->exists('agent_restricted')) {
            $workflow->agent_restricted = $request->boolean('agent_restricted');
        }
        $workflow->save();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'id' => $workflow->id,
                'name' => $workflow->name,
                'original_filename' => $workflow->original_filename,
                'agent_restricted' => (bool) $workflow->agent_restricted,
                'message' => 'Import updated.',
            ]);
        }

        return redirect()
            ->back()
            ->with('success', "Import renamed to \"{$workflow->name}\".");
    }

    public function show(Workflow $workflow)
    {
        @ini_set('memory_limit', '512M');

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        return view('workflows.show', $this->workflowService->buildShowData($workflow, $workspace, [
            'refresh_enrichment' => request()->boolean('refresh_enrichment'),
            'pool' => request()->input('pool'),
        ]));
    }

    public function map(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->updateMapping(
            $workflow,
            $request->input('mapping', []),
            $request->filled('selected_sheet') ? $request->input('selected_sheet') : null
        );

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Column mappings updated successfully.');
    }

    public function run(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $request->validate([
            'mapping_confirmed' => 'accepted',
        ]);

        $runEnrichment = $request->boolean('run_enrichment_on_import');

        $this->workflowService->queueForProcessing($workflow, [
            'mapping' => $request->input('mapping', []),
            'custom_prompt' => $request->input('custom_prompt'),
            'verification_toggles' => $request->input('verification_toggles'),
            'distribution_users' => $request->input('distribution_users'),
            'mapping_confirmed' => $request->boolean('mapping_confirmed'),
            'run_enrichment_on_import' => $runEnrichment,
            'auto_assign_setters' => $request->boolean('auto_assign_setters'),
        ]);

        $message = $runEnrichment
            ? 'Import started with AI enrichment. Leads will appear as rows are processed.'
            : 'Upload started (no AI). Leads will appear as the file is imported.';

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', $message);
    }

    public function enrich(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->startEnrichment($workflow);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'AI enrichment started for imported leads.');
    }

    public function distribute(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $request->validate([
            'distribution_users' => 'nullable|array|min:1',
            'distribution_users.*' => 'integer|exists:users,id',
        ]);

        $count = $this->workflowService->distributeToSetters(
            $workflow,
            $request->input('distribution_users'),
        );

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', "{$count} enriched leads distributed to appointment setters.");
    }

    public function assignLeads(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        try {
            $data = $request->validate([
                'team_lead_id' => 'required|integer|exists:users,id',
                'lead_count' => 'required|integer|min:1|max:5000',
                'member_ids' => 'nullable|array',
                'member_ids.*' => 'integer|exists:users,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->assignLeadsResponse($request, false, $exception->getMessage(), $exception->errors());
        }

        $teamLead = $workspace->users()
            ->where('users.id', $data['team_lead_id'])
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', WorkflowAssignmentRoles::teamLeadRoles())
            ->first();

        if (! $teamLead) {
            return $this->assignLeadsResponse($request, false, 'Selected user is not an active team lead in this workspace.', [
                'team_lead_id' => 'Selected user is not an active team lead in this workspace.',
            ]);
        }

        $teamLeadRole = $teamLead->getWorkspaceRole($workspace->id);
        $teamMembers = WorkflowAssignmentRoles::agentsForTeamLead($workspace, $teamLead);
        $agentCount = $teamMembers->count();

        if ($agentCount === 0) {
            $agentLabel = $teamLeadRole === WorkflowAssignmentRoles::closerTeamLeadRole()
                ? 'closers'
                : 'appointment setters';

            return $this->assignLeadsResponse($request, false, "No active {$agentLabel} are available for this team lead.", [
                'lead_count' => "Add at least one active {$agentLabel} under this team lead before assigning leads.",
            ]);
        }

        $memberIds = collect($data['member_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $available = $this->setterDistribution->unassignedWorkflowLeadCount($workflow);
        if ($available === 0) {
            return $this->assignLeadsResponse($request, false, 'No unassigned enriched leads remain in this import.', [
                'lead_count' => 'No unassigned enriched leads remain in this import.',
            ]);
        }

        $requested = min((int) $data['lead_count'], $available);
        $assigned = $this->setterDistribution->assignWorkflowLeadsToTeamLead(
            $workspace,
            $workflow,
            $teamLead,
            $requested,
            Auth::user(),
            $memberIds,
        );

        if ($assigned === 0) {
            return $this->assignLeadsResponse($request, false, 'No leads could be assigned.', [
                'lead_count' => 'No leads could be assigned. Ensure active team members exist on this team.',
            ]);
        }

        $memberLabel = $memberIds === []
            ? "{$teamLead->name}'s team"
            : (count($memberIds) === 1 ? 'the selected team member' : count($memberIds).' selected team members');

        return $this->assignLeadsResponse(
            $request,
            true,
            "Assigned {$assigned} lead(s) to {$memberLabel}.",
            [],
            $assigned,
            $available - $assigned,
        );
    }

    public function unassignLeads(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        try {
            $data = $request->validate([
                'lead_count' => 'required|integer|min:1|max:5000',
                'agent_ids' => 'nullable|array',
                'agent_ids.*' => 'integer|exists:users,id',
            ]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return $this->assignLeadsResponse($request, false, $exception->getMessage(), $exception->errors());
        }

        try {
            $agentIds = collect($data['agent_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            $available = $this->setterDistribution->assignedWorkflowLeadCount($workflow, $agentIds);
            if ($available === 0) {
                return $this->assignLeadsResponse($request, false, 'No assigned leads are available to unassign for this import.', [
                    'lead_count' => 'No assigned leads are available to unassign for this import.',
                ]);
            }

            $requested = min((int) $data['lead_count'], $available);
            $unassigned = $this->setterDistribution->unassignWorkflowLeadsToPool(
                $workspace,
                $workflow,
                $requested,
                Auth::user(),
                $agentIds,
            );

            if ($unassigned === 0) {
                return $this->assignLeadsResponse($request, false, 'No leads could be unassigned.', [
                    'lead_count' => 'No leads could be unassigned.',
                ]);
            }

            $scopeLabel = $agentIds === []
                ? 'all agents'
                : (count($agentIds) === 1 ? 'the selected agent' : count($agentIds).' selected agents');

            return $this->unassignLeadsResponse(
                $request,
                true,
                "Unassigned {$unassigned} lead(s) from {$scopeLabel}. They are back in the assign pool.",
                [],
                $unassigned,
                $this->setterDistribution->unassignedWorkflowLeadCount($workflow),
                $this->setterDistribution->assignedWorkflowLeadCount($workflow),
            );
        } catch (\Throwable $e) {
            report($e);

            return $this->assignLeadsResponse($request, false, 'Unassign failed: '.$e->getMessage(), [
                'lead_count' => 'Unassign failed. Please try again.',
            ]);
        }
    }

    public function agentAccess(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $selectedIds = $workflow->agentAccess()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $agents = $workspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', [
                'appointment_setter',
                'closer',
                'appointment_setter_team_lead',
                'closers_team_lead',
            ])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn ($user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'selected' => in_array((int) $user->id, $selectedIds, true),
            ])
            ->values()
            ->all();

        return response()->json([
            'workflow_id' => (int) $workflow->id,
            'workflow_name' => (string) $workflow->name,
            'agent_restricted' => (bool) $workflow->agent_restricted,
            'agents' => $agents,
            'selected_ids' => $selectedIds,
            'mode' => $selectedIds === [] ? 'all_visible_agents' : 'allowlist',
        ]);
    }

    public function syncAgentAccess(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $data = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'agent_restricted' => 'sometimes|boolean',
        ]);

        $allowedWorkspaceUserIds = $workspace->users()
            ->wherePivot('status', 'active')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $userIds = collect($data['user_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && in_array($id, $allowedWorkspaceUserIds, true))
            ->unique()
            ->values()
            ->all();

        $workflow->visibleAgents()->sync($userIds);

        if ($request->exists('agent_restricted')) {
            $workflow->agent_restricted = $request->boolean('agent_restricted');
            $workflow->save();
        } elseif ($userIds !== [] && $workflow->agent_restricted) {
            // Sharing with specific agents implies the file should be visible.
            $workflow->agent_restricted = false;
            $workflow->save();
        }

        return response()->json([
            'ok' => true,
            'workflow_id' => (int) $workflow->id,
            'selected_ids' => $userIds,
            'agent_restricted' => (bool) $workflow->agent_restricted,
            'message' => $userIds === []
                ? 'All agents with assigned leads can see this file (when Visible).'
                : 'File visibility limited to '.count($userIds).' selected agent(s).',
        ]);
    }

    public function dispositions(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $payload = $this->dashboardService->dispositionHistoryForWorkflow((int) $workflow->id);

        return response()->json([
            'workflow_id' => (int) $workflow->id,
            'workflow_name' => (string) $workflow->name,
            ...$payload,
        ]);
    }

    /**
     * @param  array<string, string|list<string>>  $errors
     */
    protected function unassignLeadsResponse(
        Request $request,
        bool $success,
        string $message,
        array $errors = [],
        int $unassigned = 0,
        ?int $remaining = null,
        ?int $stillAssigned = null,
    ) {
        if ($request->expectsJson()) {
            if ($success) {
                return response()->json([
                    'message' => $message,
                    'unassigned' => $unassigned,
                    'remaining' => $remaining,
                    'assigned' => $stillAssigned,
                ]);
            }

            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], 422);
        }

        if ($success) {
            return redirect()->back()->with('success', $message);
        }

        return redirect()->back()->withErrors($errors);
    }

    /**
     * @param  array<string, string|list<string>>  $errors
     */
    protected function assignLeadsResponse(
        Request $request,
        bool $success,
        string $message,
        array $errors = [],
        int $assigned = 0,
        ?int $remaining = null,
    ) {
        if ($request->expectsJson()) {
            if ($success) {
                return response()->json([
                    'message' => $message,
                    'assigned' => $assigned,
                    'remaining' => $remaining,
                ]);
            }

            return response()->json([
                'message' => $message,
                'errors' => $errors,
            ], 422);
        }

        if ($success) {
            return redirect()->back()->with('success', $message);
        }

        return redirect()->back()->withErrors($errors);
    }

    public function pause(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->pauseProcessing($workflow);

        return redirect()->back()->with('success', 'Pipeline processing stopped. You can resume when ready.');
    }

    public function resume(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->resumeProcessing($workflow);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Pipeline processing resumed.');
    }

    public function retryFailed(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->retryFailedLeads($workflow);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Failed leads queued for re-enrichment.');
    }

    public function activate(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->activateStoredPipeline($workflow);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Pipeline activated. AI enrichment and setter distribution started.');
    }

    public function destroy(Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $this->workflowService->delete($workflow);

        return redirect()->route('admin.workflows.index')->with('success', 'Workflow pipeline and all lead records deleted successfully.');
    }

    public function leadShow(WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $team = $workspace->users;
        $lead->load(['workflow.workspace', 'verifier', 'activities.user', 'setter', 'closer', 'campaign', 'leadList']);

        $user = Auth::user();

        if (! $this->pipelineService->canView($user, $lead, $workspace)) {
            abort(403, 'You do not have access to this lead.');
        }

        $setterStatuses = config('sales_ops.setter_statuses', []);
        $closerStatuses = config('sales_ops.closer_statuses', []);
        $pipelinePhases = config('sales_ops.pipeline_phases', []);
        $activityTypes = config('sales_ops.activity_types', []);
        $canEditSetter = $lead->pipeline_phase === 'with_setter'
            && ! $lead->isSetterLocked()
            && (
                $user->canAccessAdminPortal($workspace->id)
                || ($user->isAppointmentSetter($workspace->id) && (int) $lead->assigned_user_id === $user->id)
            );
        $canEditCloser = $lead->pipeline_phase === 'with_closer'
            && ! $lead->isCloserLocked()
            && (
                $user->canAccessAdminPortal($workspace->id)
                || (
                    $user->isCloser($workspace->id)
                    && (
                        (int) $lead->assigned_user_id === $user->id
                        || (int) $lead->assigned_closer_id === $user->id
                    )
                )
            );
        $showSetterHistory = in_array($lead->pipeline_phase, ['appointment_settled', 'with_closer', 'closed'], true)
            && $lead->hasSetterHistory()
            && (
                $user->isCloser($workspace->id)
                || $user->isClosersTeamLead($workspace->id)
                || $user->canAccessAdminPortal($workspace->id)
            );

        $isAdminView = LeadRoute::isAdminContext();
        $lead->loadMissing('campaign', 'leadList');

        return view('workflows.lead_show', compact(
            'lead', 'team', 'workspace', 'setterStatuses', 'closerStatuses',
            'pipelinePhases', 'activityTypes', 'canEditSetter', 'canEditCloser', 'showSetterHistory', 'user', 'isAdminView'
        ));
    }

    public function leadUpdate(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $user = Auth::user();

        if ($request->filled('setter_status')) {
            $this->pipelineService->updateSetterStatus(
                $user,
                $lead,
                $workspace,
                $request->input('setter_status'),
                $request->input('notes')
            );

            return redirect()->to(LeadRoute::show($lead))->with('success', 'Lead status updated.');
        }

        if ($request->filled('closer_status')) {
            $this->pipelineService->updateCloserStatus(
                $user,
                $lead,
                $workspace,
                $request->input('closer_status'),
                $request->input('notes')
            );

            return redirect()->to(LeadRoute::show($lead))->with('success', 'Lead status updated.');
        }

        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        $lead->update($data);

        return redirect()->to(LeadRoute::show($lead))->with('success', 'Lead record updated.');
    }

    public function leadDestroy(WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $this->leadService->delete($lead);

        if (LeadRoute::isAdminContext()) {
            return redirect()->route('admin.workflows.index')->with('success', 'Lead record deleted successfully.');
        }

        return redirect()->route('portal.dashboard')->with('success', 'Lead record deleted successfully.');
    }

    public function approveLead(WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $this->verificationService->approve($lead, Auth::user());

        return redirect()->back()->with('success', 'Lead approved and released to the team.');
    }

    public function rejectLead(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        $this->verificationService->reject($lead, Auth::user(), $data['rejection_reason'] ?? null);

        return redirect()->back()->with('success', 'Lead rejected and removed from the pipeline.');
    }

    public function bulkApproveLeads(Request $request, Workflow $workflow)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureWorkflowBelongsToWorkspace($workflow, $workspace);

        $data = $request->validate([
            'lead_ids' => 'required|array|min:1',
            'lead_ids.*' => 'integer|exists:workflow_leads,id',
        ]);

        $approved = $this->verificationService->approveMany($workflow, $data['lead_ids'], Auth::user());

        return redirect()->back()->with('success', "{$approved} lead(s) approved and released to the team.");
    }

    public function workspaceIndex()
    {
        $user = Auth::user();
        $user->ensureAdminPortalWorkspace();
        $workspaces = $user->adminSwitchableWorkspaces();
        $activeWorkspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->canAccessAdminModule('user_management', $activeWorkspace->id)) {
            abort(403, 'You do not have access to user management.');
        }

        $activeWorkspace->load(['admin:id,name,email'])->loadCount(['workflows', 'users']);
        $activeMemberCount = $activeWorkspace->users()->wherePivot('status', 'active')->count();
        $suspendedMemberCount = $activeWorkspace->users()->wherePivot('status', 'suspended')->count();
        $members = $activeWorkspace->users()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.password_hint',
                'users.current_workspace_id',
                'users.created_at',
                'users.updated_at',
            ])
            ->orderBy('users.name')
            ->paginate(config('pagination.members_per_page'))
            ->withQueryString();

        // One query for team-lead options + campaign map instead of three full membership scans.
        $teamLeadRows = $activeWorkspace->users()
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['appointment_setter_team_lead', 'closers_team_lead'])
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
        $setterTeamLeads = $teamLeadRows->filter(fn ($u) => ($u->pivot->role ?? '') === 'appointment_setter_team_lead')->values();
        $closerTeamLeads = $teamLeadRows->filter(fn ($u) => ($u->pivot->role ?? '') === 'closers_team_lead')->values();
        $teamLeadNames = $teamLeadRows->pluck('name', 'id');
        $teamLeadCampaignIds = $teamLeadRows->mapWithKeys(
            fn ($lead) => [(int) $lead->id => (int) ($lead->pivot->campaign_id ?? 0)]
        );
        $campaigns = $activeWorkspace->campaigns()
            ->orderBy('name')
            ->get(['id', 'name']);
        $campaignNames = $campaigns->pluck('name', 'id');
        $teamMemberCounts = DB::table('workspace_user')
            ->where('workspace_id', $activeWorkspace->id)
            ->whereNotNull('team_lead_user_id')
            ->selectRaw('team_lead_user_id, COUNT(*) as member_count')
            ->groupBy('team_lead_user_id')
            ->pluck('member_count', 'team_lead_user_id');
        $workspaces->each(function (Workspace $workspace) {
            $workspace->loadMissing('admin:id,name');
            $workspace->loadCount(['workflows', 'users']);
        });

        $availablePhoneLines = [];
        $suggestedExtension = '1021';
        try {
            $agentService = app(\App\Services\Communications\CommunicationsAgentService::class);
            $availablePhoneLines = $agentService->availablePhoneLines($activeWorkspace);
            $suggestedExtension = $agentService->suggestNextExtension($activeWorkspace);
        } catch (\Throwable) {
            $availablePhoneLines = [];
        }

        return view('workflows.workspaces', compact(
            'workspaces',
            'activeWorkspace',
            'members',
            'activeMemberCount',
            'suspendedMemberCount',
            'setterTeamLeads',
            'closerTeamLeads',
            'teamLeadNames',
            'campaigns',
            'campaignNames',
            'teamLeadCampaignIds',
            'teamMemberCounts',
            'availablePhoneLines',
            'suggestedExtension',
        ));
    }

    public function workspaceStore(Request $request)
    {
        $user = Auth::user();
        if (! $user->isPlatformSuperAdmin()) {
            abort(403, 'Only the Super Admin can add workspaces.');
        }

        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $workspace = $this->workspaceManager->createWorkspace($user, $request->input('name'));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => "Workspace '{$workspace->name}' created successfully.",
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ],
            ]);
        }

        return redirect()->route('admin.workspaces.index')->with('success', "Workspace '{$workspace->name}' created successfully.");
    }

    public function workspaceSwitch(Workspace $workspace)
    {
        $user = Auth::user();

        if (request()->is('admin*') && ! $user->isWorkspaceAdmin($workspace->id)) {
            if (request()->expectsJson()) {
                return response()->json([
                    'message' => 'You can only switch to workspaces you administer.',
                ], 403);
            }

            abort(403, 'You can only switch to workspaces you administer.');
        }

        $this->workspaceManager->switchWorkspace($user, $workspace);

        if (request()->is('portal*') || request()->routeIs('portal.*')) {
            if (! $user->canAccessPortal($workspace->id)) {
                if (request()->expectsJson()) {
                    return response()->json([
                        'message' => 'You can only switch to workspaces where you have agent portal access.',
                    ], 403);
                }

                abort(403, 'You can only switch to workspaces where you have agent portal access.');
            }
        }

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Switched to workspace '{$workspace->name}'.",
            ]);
        }

        $redirect = request()->is('portal*') || request()->routeIs('portal.*')
            ? route('portal.dashboard')
            : (request()->headers->get('referer') && (
                str_contains(request()->headers->get('referer'), '/admin/usermanagement')
                || str_contains(request()->headers->get('referer'), '/admin/workspaces')
            )
                ? route('admin.workspaces.index')
                : route('admin.workflows.index'));

        return redirect($redirect)->with('success', "Switched to workspace '{$workspace->name}'.");
    }

    public function verifyLeadEmail(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        return response()->json($this->leadService->verifyEmail(
            $lead,
            $request->input('email')
        ));
    }

    public function analyzeLeadEmail(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        return response()->json($this->leadService->analyzeEmailContent(
            $request->input('subject', ''),
            $request->input('body', '')
        ));
    }

    public function checkLeadDomain(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        return response()->json($this->leadService->checkDomain(
            $lead,
            $request->input('domain')
        ));
    }
}
