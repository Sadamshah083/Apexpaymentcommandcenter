<?php

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceManager;
use App\Services\Workspace\WorkspaceMemberService;
use App\Services\Pipeline\LeadPipelineService;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workflow\WorkflowLeadService;
use App\Services\Workflow\WorkflowLeadVerificationService;
use App\Services\Workflow\WorkflowService;
use App\Services\SalesOps\DiscoveryQualificationService;
use App\Support\SalesOps;
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
    ) {}

    public function index(Request $request)
    {
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

        return view('workflows.create', compact('workspace'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
            'processing_mode' => 'required|in:store_only,full_pipeline,import_only,import_and_enrich',
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $workflow = $this->workflowService->createFromUpload(
            $workspace,
            $request->input('name'),
            $request->file('file'),
            $request->input('processing_mode')
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
            'tag_ids' => $request->input('tag_ids', []),
            'tag_names' => $request->input('tag_names', ''),
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
        $lead->load(['workflow.workspace', 'verifier', 'activities.user', 'setter', 'closer', 'tags', 'leadList']);

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

        return view('workflows.lead_show', compact(
            'lead', 'team', 'workspace', 'setterStatuses', 'closerStatuses',
            'pipelinePhases', 'activityTypes', 'canEditSetter', 'canEditCloser', 'showSetterHistory', 'user'
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

            return redirect()->route('portal.leads.show', $lead->id)->with('success', 'Lead status updated.');
        }

        if ($request->filled('closer_status')) {
            $this->pipelineService->updateCloserStatus(
                $user,
                $lead,
                $workspace,
                $request->input('closer_status'),
                $request->input('notes')
            );

            return redirect()->route('portal.leads.show', $lead->id)->with('success', 'Lead status updated.');
        }

        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        $lead->update($data);

        return redirect()->route('portal.leads.show', $lead->id)->with('success', 'Lead record updated.');
    }

    public function leadDestroy(WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $this->leadService->delete($lead);

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

        $activeWorkspace->load(['admin:id,name,email', 'users'])->loadCount(['workflows', 'users']);
        $workspaces->each(function (Workspace $workspace) {
            $workspace->loadMissing('admin:id,name');
            $workspace->loadCount(['workflows', 'users']);
        });

        return view('workflows.workspaces', compact('workspaces', 'activeWorkspace'));
    }

    public function workspaceStore(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $workspace = $this->workspaceManager->createWorkspace(Auth::user(), $request->input('name'));

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
