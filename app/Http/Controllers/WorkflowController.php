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

    public function index(Request $request)
    {
        if ($request->is('admin*') || $request->routeIs('admin.*')) {
            $user = Auth::user();
            $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
            $data = $this->dashboardService->buildIndexData($workspace, $user, [
                'search' => $request->input('search'),
                'phase' => $request->input('phase'),
                'assigned_user_id' => $request->input('assigned_user_id'),
                'refresh_enrichment' => $request->boolean('refresh_enrichment'),
            ]);

            $data['importsWorkflows'] = $data['workflows'];
            $data['campaigns'] = $this->campaignService->campaignsWithStats($workspace);
            $summaryWorkflows = $workspace->workflows()
                ->latest()
                ->get(['id', 'name', 'original_filename', 'created_at', 'total_leads', 'failed_leads']);
            $workflowLeadStats = $summaryWorkflows->isEmpty()
                ? collect()
                : WorkflowLead::query()
                    ->selectRaw(
                        "workflow_id,
                        SUM(CASE WHEN pipeline_phase IN ('enriched', 'with_setter', 'appointment_settled', 'with_closer', 'closed')
                            AND status IN ('enriched', 'completed') THEN 1 ELSE 0 END) as enriched_count,
                        SUM(CASE WHEN ((pipeline_phase = 'closed' AND closer_status = 'sale_made') OR stage = 'closed_won')
                            THEN 1 ELSE 0 END) as closed_count"
                    )
                    ->whereIn('workflow_id', $summaryWorkflows->modelKeys())
                    ->groupBy('workflow_id')
                    ->get()
                    ->keyBy('workflow_id');

            $data['workflowSummaries'] = $summaryWorkflows
                ->map(function (Workflow $workflow) use ($workflowLeadStats) {
                    $stats = $workflowLeadStats->get($workflow->id);
                    $closedCount = (int) ($stats->closed_count ?? 0);
                    $enrichedCount = (int) ($stats->enriched_count ?? 0);
                    $totalLeadsCount = (int) ($workflow->total_leads ?? 0);

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
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
            'processing_mode' => 'required|in:store_only,full_pipeline,import_only,import_and_enrich',
            'campaign_id' => 'nullable|integer',
            'campaign_name' => 'nullable|string|max:100',
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $campaign = $this->campaignService->resolveForImport(
            $workspace,
            Auth::user(),
            $request->filled('campaign_id') ? (int) $request->input('campaign_id') : null,
            $request->input('campaign_name'),
        );

        $workflow = $this->workflowService->createFromUpload(
            $workspace,
            $request->input('name'),
            $request->file('file'),
            $request->input('processing_mode'),
            $campaign->id,
        );

        $workflow = $this->workflowService->applyAutoMappingIfNeeded($workflow);

        $mappedBusiness = $workflow->fresh()->column_mapping['business_name'] ?? null;
        $message = $mappedBusiness
            ? "Spreadsheet uploaded. AI mapped \"{$mappedBusiness}\" to Business Name."
            : 'Spreadsheet uploaded. Review the column mapping, then run the pipeline.';

        return redirect()
            ->route('admin.workflows.show', $workflow->id)
            ->with('success', $message);
    }

    public function show(Workflow $workflow)
    {
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

        $this->workflowService->queueForProcessing($workflow, [
            'mapping' => $request->input('mapping', []),
            'custom_prompt' => $request->input('custom_prompt'),
            'verification_toggles' => $request->input('verification_toggles'),
            'distribution_users' => $request->input('distribution_users'),
            'mapping_confirmed' => $request->boolean('mapping_confirmed'),
            'run_enrichment_on_import' => $request->boolean('run_enrichment_on_import'),
            'auto_assign_setters' => $request->boolean('auto_assign_setters'),
        ]);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Import started. Leads will appear as rows are processed.');
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

        if ($teamLead->getWorkspaceRole($workspace->id) !== WorkflowAssignmentRoles::setterTeamLeadRole()) {
            return $this->assignLeadsResponse($request, false, 'Enriched import leads must be assigned to an Appointment Setter Team Lead.', [
                'team_lead_id' => 'Enriched import leads must be assigned to an Appointment Setter Team Lead. Closers Team Lead handles settled appointments.',
            ]);
        }

        $teamMembers = WorkflowAssignmentRoles::settersForTeamLead($workspace, (int) $teamLead->id);
        $allSetters = WorkflowAssignmentRoles::activeSettersFor($workspace);
        $setterCount = $teamMembers->isNotEmpty() ? $teamMembers->count() : $allSetters->count();

        if ($setterCount === 0) {
            return $this->assignLeadsResponse($request, false, 'No active appointment setters are available in this workspace.', [
                'lead_count' => 'Add at least one active appointment setter before assigning leads.',
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
                'lead_count' => 'No leads could be assigned. Ensure active appointment setters exist on this team.',
            ]);
        }

        $memberLabel = $memberIds === []
            ? "{$teamLead->name}'s setter team"
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
            ->orderBy('users.name')
            ->paginate(config('pagination.members_per_page'))
            ->withQueryString();
        $setterTeamLeads = $activeWorkspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'appointment_setter_team_lead')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
        $closerTeamLeads = $activeWorkspace->users()
            ->wherePivot('status', 'active')
            ->wherePivot('role', 'closers_team_lead')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
        $teamLeadNames = $activeWorkspace->users()
            ->wherePivotIn('role', ['appointment_setter_team_lead', 'closers_team_lead'])
            ->get(['users.id', 'users.name'])
            ->pluck('name', 'id');
        $campaigns = $activeWorkspace->campaigns()
            ->orderBy('name')
            ->get(['id', 'name']);
        $campaignNames = $campaigns->pluck('name', 'id');
        $teamLeadCampaignIds = $activeWorkspace->users()
            ->wherePivotIn('role', ['appointment_setter_team_lead', 'closers_team_lead'])
            ->get()
            ->mapWithKeys(fn ($lead) => [(int) $lead->id => (int) ($lead->pivot->campaign_id ?? 0)]);
        $workspaces->each(function (Workspace $workspace) {
            $workspace->loadMissing('admin:id,name');
            $workspace->loadCount(['workflows', 'users']);
        });

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
        ));
    }

    public function workspaceStore(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $workspace = $this->workspaceManager->createWorkspace(Auth::user(), $request->input('name'));

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
            : (request()->headers->get('referer') && str_contains(request()->headers->get('referer'), '/admin/workspaces')
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
