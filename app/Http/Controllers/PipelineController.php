<?php

namespace App\Http\Controllers;

use App\Models\WorkflowLead;
use App\Services\Pipeline\CloserAssignmentService;
use App\Services\Pipeline\LeadPipelineService;
use App\Services\Pipeline\RoleDashboardService;
use App\Services\Pipeline\SetterDistributionService;
use App\Services\Portal\PortalDashboardService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PipelineController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected RoleDashboardService $dashboardService,
        protected PortalDashboardService $portalDashboard,
        protected LeadPipelineService $pipelineService,
        protected CloserAssignmentService $closerAssignment,
        protected SetterDistributionService $setterDistribution,
    ) {}

    public function portalDashboard()
    {
        $user = Auth::user();
        $route = $user->portalDashboardRoute();

        if ($route === 'portal.login') {
            abort(403, 'Your account does not have portal access.');
        }

        return redirect()->route($route);
    }

    public function setterDashboard(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isAppointmentSetter($workspace->id)) {
            abort(403);
        }

        if (! $user->canAccessPortalModule('setter_leads', $workspace->id)) {
            abort(403, 'You do not have access to your lead queue.');
        }

        $leads = $this->dashboardService->setterLeads($workspace, $user, [
            'search' => $request->input('search'),
        ]);

        $setterStatuses = config('sales_ops.setter_statuses', []);
        $dashboard = $this->portalDashboard->forUser($user, $workspace);

        return view('pipeline.setter.index', compact('workspace', 'leads', 'user', 'setterStatuses', 'dashboard'));
    }

    public function setterTeamDashboard(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isAppointmentSetterTeamLead($workspace->id)) {
            abort(403);
        }

        if (! $user->canAccessPortalModule('setter_team', $workspace->id)) {
            abort(403, 'You do not have access to the setter team dashboard.');
        }

        $leads = $this->dashboardService->setterTeamLeads($workspace, $user, [
            'search' => $request->input('search'),
            'phase' => $request->input('phase'),
            'setter' => $request->input('setter'),
        ]);
        $teamMetrics = $this->dashboardService->setterTeamMetrics($workspace);
        $setters = $this->dashboardService->availableSetters($workspace);
        $unassignedLeads = $this->setterDistribution->unassignedLeadCount($workspace);
        $dashboard = $this->portalDashboard->forUser($user, $workspace);

        return view('pipeline.setter-team.index', compact('workspace', 'leads', 'user', 'teamMetrics', 'dashboard', 'setters', 'unassignedLeads'));
    }

    public function closerTeamDashboard(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isClosersTeamLead($workspace->id)) {
            abort(403);
        }

        if (! $user->canAccessPortalModule('closer_team', $workspace->id)) {
            abort(403, 'You do not have access to the closer team dashboard.');
        }

        $leads = $this->dashboardService->closerTeamLeads($workspace, $user, [
            'search' => $request->input('search'),
            'phase' => $request->input('phase'),
            'closer' => $request->input('closer'),
        ]);
        $teamMetrics = $this->dashboardService->closerTeamMetrics($workspace);
        $closers = $this->dashboardService->availableClosers($workspace);
        $dashboard = $this->portalDashboard->forUser($user, $workspace);

        return view('pipeline.closer-team.index', compact('workspace', 'leads', 'user', 'teamMetrics', 'dashboard', 'closers'));
    }

    public function closerTeamQueue(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isClosersTeamLead($workspace->id) && ! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }

        if ($user->isClosersTeamLead($workspace->id) && ! $user->canAccessPortalModule('closer_queue', $workspace->id)) {
            abort(403, 'You do not have access to the closer queue.');
        }

        $leads = $this->dashboardService->closerTeamQueue($workspace, [
            'search' => $request->input('search'),
        ]);
        $closers = $this->dashboardService->availableClosers($workspace);

        return view('pipeline.closer-team.queue', compact('workspace', 'leads', 'user', 'closers'));
    }

    public function closerDashboard(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isCloser($workspace->id)) {
            abort(403);
        }

        if (! $user->canAccessPortalModule('closer_leads', $workspace->id)) {
            abort(403, 'You do not have access to your closer leads.');
        }

        $leads = $this->dashboardService->closerLeads($workspace, $user, [
            'search' => $request->input('search'),
        ]);

        $dashboard = $this->portalDashboard->forUser($user, $workspace);

        return view('pipeline.closer.index', compact('workspace', 'leads', 'user', 'dashboard'));
    }

    public function assignCloser(Request $request, WorkflowLead $lead)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'closer_id' => 'required|exists:users,id',
        ]);

        $closer = $workspace->users()->where('users.id', $data['closer_id'])->firstOrFail();
        $this->closerAssignment->assign($workspace, $lead, $closer, $user);

        return redirect()->back()->with('success', 'Lead assigned to closer.');
    }

    public function updateSetterStatus(Request $request, WorkflowLead $lead)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'setter_status' => 'required|string',
            'notes' => 'nullable|string|max:5000',
        ]);

        $this->pipelineService->updateSetterStatus(
            $user,
            $lead,
            $workspace,
            $data['setter_status'],
            $data['notes'] ?? null
        );

        return redirect()->back()->with('success', 'Lead status updated.');
    }

    public function updateCloserStatus(Request $request, WorkflowLead $lead)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'closer_status' => 'required|string',
            'notes' => 'nullable|string|max:5000',
        ]);

        $this->pipelineService->updateCloserStatus(
            $user,
            $lead,
            $workspace,
            $data['closer_status'],
            $data['notes'] ?? null
        );

        return redirect()->back()->with('success', 'Lead status updated.');
    }

    public function assignSetterLeads(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->isAppointmentSetterTeamLead($workspace->id) && ! $user->canAccessAdminPortal($workspace->id)) {
            abort(403);
        }

        if ($user->isAppointmentSetterTeamLead($workspace->id) && ! $user->canAccessPortalModule('assign_leads', $workspace->id)) {
            abort(403, 'You do not have access to assign leads.');
        }

        $data = $request->validate([
            'setter_id' => 'required|integer|exists:users,id',
            'lead_count' => 'required|integer|min:1|max:500',
        ]);

        $setter = $workspace->users()
            ->where('users.id', $data['setter_id'])
            ->wherePivot('role', 'appointment_setter')
            ->wherePivot('status', 'active')
            ->first();

        if (! $setter) {
            return redirect()->back()->withErrors(['setter_id' => 'Selected user is not an active appointment setter.']);
        }

        $available = $this->setterDistribution->unassignedLeadCount($workspace);
        if ($available === 0) {
            return redirect()->back()->withErrors(['lead_count' => 'No unassigned leads are available to assign.']);
        }

        $assigned = $this->setterDistribution->assignLeadsToSetter(
            $workspace,
            $setter,
            (int) $data['lead_count'],
            $user
        );

        if ($assigned === 0) {
            return redirect()->back()->withErrors(['lead_count' => 'No leads could be assigned. The setter may be at capacity or no leads are available.']);
        }

        return redirect()->back()->with('success', "Assigned {$assigned} lead(s) to {$setter->name}.");
    }
}
