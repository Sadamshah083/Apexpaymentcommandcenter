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
use App\Services\Workflow\WorkflowService;
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
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        return view('workflows.index', $this->dashboardService->buildIndexData($workspace, $user, [
            'search' => $request->input('search'),
            'stage' => $request->input('stage'),
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

        $this->workflowService->queueForProcessing($workflow, [
            'mapping' => $request->input('mapping', []),
            'custom_prompt' => $request->input('custom_prompt'),
            'verification_toggles' => $request->input('verification_toggles'),
            'distribution_users' => $request->input('distribution_users'),
        ]);

        return redirect()->route('admin.workflows.index')->with('success', 'Workflow generation queued and processing.');
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

        return view('workflows.lead_show', compact('lead', 'team'));
    }

    public function leadUpdate(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

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
            'stage' => 'required|string|in:lead,contacted,follow_up,interested,closed_won,closed_lost',
            'sale_value' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'followup_at' => 'nullable|date',
            'schedule_at' => 'nullable|date',
            'assigned_user_id' => 'nullable|exists:users,id',
        ]);

        $this->leadService->update($lead, $data);

        return redirect()->route('portal.leads.show', $lead->id)->with('success', 'Lead record updated successfully.');
    }

    public function leadDestroy(WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $this->leadService->delete($lead);

        return redirect()->route('portal.dashboard')->with('success', 'Lead record deleted successfully.');
    }

    public function workspaceIndex()
    {
        $user = Auth::user();
        $workspaces = $user->switchableWorkspaces();
        $activeWorkspace = $this->workspaceContext->resolveActiveWorkspace($user);

        return view('workflows.workspaces', compact('workspaces', 'activeWorkspace'));
    }

    public function workspaceStore(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $workspace = $this->workspaceManager->createWorkspace(Auth::user(), $request->input('name'));

        return redirect()->route('admin.workflows.index')->with('success', "Workspace '{$workspace->name}' created successfully.");
    }

    public function workspaceSwitch(Workspace $workspace)
    {
        $this->workspaceManager->switchWorkspace(Auth::user(), $workspace);

        $redirect = request()->is('portal*') || request()->routeIs('portal.*')
            ? route('portal.dashboard')
            : route('admin.workflows.index');

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
