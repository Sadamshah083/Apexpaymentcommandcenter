<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkflowLead;
use App\Models\LeadActivity;
use App\Services\Dashboard\DashboardDetailService;
use App\Services\Portal\PortalDashboardService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected PortalDashboardService $portalDashboard,
        protected DashboardDetailService $detailService,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        $data = $this->getDashboardData($workspace);
        $detail = $this->detailService->resolveAdmin($request, $workspace);

        return view('admin.dashboard.index', array_merge($data, [
            'workspace' => $workspace,
            'ops' => $this->portalDashboard->adminOperationalSummary($workspace),
            'detail' => $detail,
            'detailService' => $this->detailService,
        ]));
    }

    public function realtimeData(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        $data = $this->getDashboardData($workspace);
        $detail = $this->detailService->resolveAdmin($request, $workspace);

        return response()->json(array_merge($data, [
            'ops' => $this->portalDashboard->adminOperationalSummary($workspace),
            'detail' => $detail ? [
                'key' => $detail['key'] ?? null,
                'total' => $detail['total'] ?? 0,
                'stats' => $detail['stats'] ?? [],
            ] : null,
        ]));
    }

    protected function getDashboardData($workspace): array
    {
        $workflowIds = $workspace->workflows()->pluck('id')->all();

        // 1. Pipeline (All Time)
        $totalLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)->count();
        
        $newLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->whereIn('stage', ['new_lead', 'new', 'imported'])
            ->count();

        $qualifiedLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->where('meeting_qualified', true)
                  ->orWhereIn('stage', ['connected', 'discovery_completed']);
            })
            ->count();

        $bookedLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->whereNotNull('appointment_settled_at')
                  ->orWhere('stage', 'meeting_scheduled');
            })
            ->count();

        $showedLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->whereIn('stage', ['proposal_sent', 'follow_up', 'closed_won', 'closed_lost'])
            ->count();

        $closedWonLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->where('stage', 'closed_won')
                  ->orWhere('closer_status', 'sale_made');
            })
            ->count();

        $notNowLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->where('setter_status', 'not_interested')
                  ->orWhere('closer_status', 'follow_up');
            })
            ->count();

        $deadLeads = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->where('stage', 'closed_lost')
                  ->orWhere('closer_status', 'closed_lost');
            })
            ->count();

        // 2. Conversion Rates
        $bookToShowRate = $bookedLeads > 0 ? round(($showedLeads / $bookedLeads) * 100, 1) : null;
        $showToCloseRate = $showedLeads > 0 ? round(($closedWonLeads / $showedLeads) * 100, 1) : null;
        $overallCloseRate = $totalLeads > 0 ? round(($closedWonLeads / $totalLeads) * 100, 1) : null;
        
        $avgClosedVolume = WorkflowLead::whereIn('workflow_id', $workflowIds)
            ->where(function ($q) {
                $q->where('stage', 'closed_won')
                  ->orWhere('closer_status', 'sale_made');
            })
            ->avg('sale_value') ?: 0.0;

        $totalDials = LeadActivity::where('type', 'dial')
            ->whereHas('lead.workflow', function ($q) use ($workspace) {
                $q->where('workspace_id', $workspace->id);
            })
            ->count();

        // 3. Leads by Fronter (Appointment Setters)
        $setters = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->get()
            ->map(function ($user) use ($workflowIds) {
                $leadsCount = WorkflowLead::whereIn('workflow_id', $workflowIds)
                    ->where('assigned_setter_id', $user->id)
                    ->count();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'leads_logged' => $leadsCount,
                ];
            })
            ->sortByDesc('leads_logged')
            ->values()
            ->all();

        // 4. Leads by Closer
        $closers = $workspace->users()
            ->wherePivotIn('role', ['closer', 'closers_team_lead'])
            ->get()
            ->map(function ($user) use ($workflowIds) {
                $dealsClosed = WorkflowLead::whereIn('workflow_id', $workflowIds)
                    ->where('assigned_closer_id', $user->id)
                    ->where(function ($q) {
                        $q->where('stage', 'closed_won')
                          ->orWhere('closer_status', 'sale_made');
                    })
                    ->count();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'deals_closed' => $dealsClosed,
                ];
            })
            ->sortByDesc('deals_closed')
            ->values()
            ->all();

        return [
            'pipeline' => [
                'total_leads' => $totalLeads,
                'new' => $newLeads,
                'qualified' => $qualifiedLeads,
                'booked' => $bookedLeads,
                'showed' => $showedLeads,
                'closed_won' => $closedWonLeads,
                'not_now' => $notNowLeads,
                'dead' => $deadLeads,
            ],
            'conversion_rates' => [
                'book_to_show_rate' => $bookToShowRate,
                'show_to_close_rate' => $showToCloseRate,
                'overall_close_rate' => $overallCloseRate,
                'avg_closed_volume' => round($avgClosedVolume, 2),
                'total_dials' => $totalDials,
                'total_closes' => $closedWonLeads,
            ],
            'setters' => $setters,
            'closers' => $closers,
            'workflows' => $workspace->workflows()
                ->latest()
                ->get()
                ->map(function ($wf) {
                    $closedCount = WorkflowLead::where('workflow_id', $wf->id)
                        ->where(function ($q) {
                            $q->where('stage', 'closed_won')
                              ->orWhere('closer_status', 'sale_made');
                        })
                        ->count();

                    $enrichedCount = (int) $wf->enriched_leads;
                    $totalLeadsCount = (int) $wf->total_leads;

                    return [
                        'id' => $wf->id,
                        'name' => $wf->name,
                        'filename' => $wf->original_filename,
                        'status' => $wf->status,
                        'created_at' => $wf->created_at ? $wf->created_at->toDateString() : '',
                        'total_leads' => $totalLeadsCount,
                        'enriched_leads' => $enrichedCount,
                        'failed_leads' => (int) $wf->failed_leads,
                        'closed_deals' => $closedCount,
                        'enrichment_rate' => $totalLeadsCount > 0 ? round(($enrichedCount / $totalLeadsCount) * 100, 1) : 0,
                        'close_rate' => $totalLeadsCount > 0 ? round(($closedCount / $totalLeadsCount) * 100, 1) : 0,
                    ];
                })
                ->all(),
        ];
    }
}
