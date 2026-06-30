<?php

namespace App\Http\Controllers;

use App\Models\WorkflowLead;
use App\Models\Workspace;
use App\Services\SalesOps\LeadActivityService;
use App\Services\SalesOps\LeadReactivationService;
use App\Services\SalesOps\SdrPerformanceService;
use App\Services\Workspace\WorkspaceContextService;
use App\Support\SalesOps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SalesOpsController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected SdrPerformanceService $performance,
        protected LeadReactivationService $reactivation,
        protected LeadActivityService $activityService,
    ) {}

    public function index()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $overview = $this->performance->workspaceOverview($workspace);
        $leaderboard = $this->performance->teamLeaderboard($workspace, 'week');

        return view('sales-ops.index', compact('workspace', 'overview', 'leaderboard'));
    }

    public function performance(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $period = $request->input('period', 'week');
        $leaderboard = $this->performance->teamLeaderboard($workspace, $period);

        return view('sales-ops.performance', compact('workspace', 'leaderboard', 'period'));
    }

    public function distribution()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $sdrLoad = $this->performance->sdrLoad($workspace);

        return view('sales-ops.distribution', compact('workspace', 'sdrLoad'));
    }

    public function reactivation()
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $candidates = $this->reactivation->candidates($workspace, 50);
        $sources = config('sales_ops.reactivation_sources', []);

        return view('sales-ops.reactivation', compact('workspace', 'candidates', 'sources'));
    }

    public function enrollReactivation(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'source' => 'required|string',
        ]);

        $this->reactivation->enroll($lead, $data['source']);

        return redirect()->back()->with('success', 'Lead enrolled in reactivation program.');
    }

    public function sdrPerformance()
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        $daily = $this->performance->dailyMetrics($user, $workspace);
        $weekly = $this->performance->weeklyMetrics($user, $workspace);
        $quotas = config('sales_ops.daily_quotas', []);
        $weeklyQuotas = config('sales_ops.weekly_quotas', []);

        return view('sales-ops.sdr-performance', compact('workspace', 'daily', 'weekly', 'quotas', 'weeklyQuotas'));
    }

    public function aePipeline()
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        if (! $user->canAccessAdminPortal($workspace->id) && ! $user->isCloser($workspace->id)) {
            abort(403);
        }

        $workflowIds = $workspace->workflows()->pluck('id');
        $leads = WorkflowLead::query()
            ->whereIn('workflow_id', $workflowIds)
            ->where('status', 'completed')
            ->whereIn('stage', ['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost'])
            ->latest('updated_at')
            ->paginate(25);

        return view('sales-ops.ae-pipeline', compact('workspace', 'leads'));
    }

    public function logActivity(Request $request, WorkflowLead $lead)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());
        $this->workspaceContext->ensureLeadBelongsToWorkspace($lead, $workspace);

        $data = $request->validate([
            'type' => 'required|string',
            'outcome' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:2000',
        ]);

        $this->activityService->log(
            $lead,
            Auth::user(),
            $data['type'],
            $data['outcome'] ?? null,
            $data['notes'] ?? null
        );

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'Activity logged.');
    }
}
