<?php

namespace App\Http\Controllers;

use App\Models\Workflow;
use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceManager;
use App\Services\Workspace\WorkspaceMemberService;
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
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        return view('workflows.index', $this->dashboardService->buildIndexData($workspace, $user, [
            'search' => $request->input('search'),
            'stage' => $request->input('stage'),
            'tier' => $request->input('tier'),
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
        ]);

        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $workflow = $this->workflowService->createFromUpload(
            $workspace,
            $request->input('name'),
            $request->file('file')
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

        return view('workflows.show', $this->workflowService->buildShowData($workflow, $workspace));
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
        ]);

        return redirect()->route('admin.workflows.show', $workflow->id)->with('success', 'Enrichment started. Leads will appear for your review when ready.');
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

        return redirect()->back()->with('success', 'Pipeline processing resumed.');
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
        $lead->load(['workflow.workspace', 'verifier', 'activities.user']);

        $discoveryMissing = $this->discoveryService->missingFields($lead);
        $crmStages = SalesOps::crmStages();
        $painPoints = config('sales_ops.pain_points', []);
        $offerTypes = config('sales_ops.offer_types', []);
        $activityTypes = config('sales_ops.activity_types', []);

        return view('workflows.lead_show', compact(
            'lead', 'team', 'workspace', 'discoveryMissing', 'crmStages', 'painPoints', 'offerTypes', 'activityTypes'
        ));
    }

    public function leadUpdate(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $stageKeys = implode(',', SalesOps::crmStageKeys());

        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:50',
            'zip_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:255',
            'input_phone' => 'nullable|string|max:50',
            'input_email' => 'nullable|string|email|max:100',
            'owner_name' => 'nullable|string|max:100',
            'direct_phone' => 'nullable|string|max:50',
            'direct_email' => 'nullable|string|max:100',
            'payment_processor' => 'nullable|string|max:100',
            'system_integration' => 'nullable|string',
            'primary_service' => 'nullable|string|max:100',
            'operating_hours' => 'nullable|string',
            'stage' => 'required|string|in:'.$stageKeys,
            'sale_value' => 'nullable|numeric|min:0',
            'monthly_processing_volume' => 'nullable|numeric|min:0',
            'current_processor' => 'nullable|string|max:100',
            'pricing_model' => 'nullable|string|max:100',
            'contract_expiration_date' => 'nullable|date',
            'pain_points' => 'nullable|array',
            'pain_points.*' => 'string',
            'offer_type' => 'nullable|string',
            'is_nurture' => 'nullable|boolean',
            'meeting_qualified' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'followup_at' => 'nullable|date',
            'schedule_at' => 'nullable|date',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $data['is_nurture'] = $request->boolean('is_nurture');
        $data['meeting_qualified'] = $request->boolean('meeting_qualified');
        $data['tier'] = SalesOps::tierFromAttempts((int) $lead->contact_attempts, $data['is_nurture']);

        if ($data['meeting_qualified'] && ! $lead->meeting_qualified_at) {
            $data['meeting_qualified_at'] = now();
        }

        $this->leadService->update($lead, $data);
        $lead->refresh();

        if ($this->discoveryService->isComplete($lead)) {
            $this->discoveryService->markDiscoveryComplete($lead, Auth::user());
        }

        return redirect()->route('portal.leads.show', $lead->id)->with('success', 'Lead record updated successfully.');
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
        $workspaces = $user->switchableWorkspaces();
        $activeWorkspace = $this->workspaceContext->resolveActiveWorkspace($user);

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
        $this->workspaceManager->switchWorkspace(Auth::user(), $workspace);

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
