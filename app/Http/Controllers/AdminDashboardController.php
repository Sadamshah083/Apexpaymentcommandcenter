<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkflowLead;
use App\Services\Dashboard\DashboardDetailService;
use App\Services\Dashboard\PipelineMetricsService;
use App\Services\Pipeline\CampaignService;
use App\Services\Portal\PortalDashboardService;
use App\Services\Workflow\WorkflowDashboardService;
use App\Services\Workspace\WorkspaceContextService;
use App\Services\Workspace\WorkspaceSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminDashboardController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
        protected PortalDashboardService $portalDashboard,
        protected DashboardDetailService $detailService,
        protected PipelineMetricsService $pipelineMetrics,
        protected WorkflowDashboardService $workflowDashboard,
        protected CampaignService $campaignService,
        protected WorkspaceSyncService $sync,
    ) {}

    public function index(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        $data = $this->getDashboardData($workspace);
        $detail = $this->detailService->resolveAdmin($request, $workspace);

        $imports = empty($detail)
            ? $this->workflowDashboard->buildIndexData($workspace, $user, [
                'search' => $request->input('search'),
                'phase' => $request->input('phase'),
                'assigned_user_id' => $request->input('assigned_user_id'),
                'refresh_enrichment' => $request->boolean('refresh_enrichment'),
            ])
            : [];

        return view('admin.dashboard.index', array_merge($data, $imports, [
            'workspace' => $workspace,
            'campaigns' => $this->campaignService->campaignsWithStats($workspace),
            'ops' => $this->portalDashboard->adminOperationalSummary($workspace),
            'detail' => $detail,
            'detailService' => $this->detailService,
            'activeSection' => $request->input('section'),
        ]));
    }

    public function realtimeData(Request $request)
    {
        $user = Auth::user();
        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);

        $data = $this->getDashboardData($workspace);
        $detail = $this->detailService->resolveAdmin($request, $workspace);

        $payload = array_merge($data, [
            'ops' => $this->serializeOpsForRealtime($workspace),
            'campaigns' => $this->serializeCampaignsForRealtime($workspace),
            'detail' => $this->detailService->toRealtimePayload($detail),
        ]);

        if ($request->input('section') === 'imports' && empty($detail)) {
            $imports = $this->workflowDashboard->buildIndexData($workspace, $user, [
                'search' => $request->input('search'),
                'phase' => $request->input('phase'),
                'assigned_user_id' => $request->input('assigned_user_id'),
            ]);
            $leads = $imports['leads'] ?? null;
            if ($leads instanceof \Illuminate\Contracts\Pagination\Paginator) {
                $payload['imports_leads'] = $this->sync->serializeLeadsCollection($leads->getCollection());
            }
        }

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeOpsForRealtime($workspace): array
    {
        $ops = $this->portalDashboard->adminOperationalSummary($workspace);
        $ops['leaderboard'] = collect($ops['leaderboard'] ?? [])
            ->map(fn (array $row) => array_merge($row, [
                'detail_url' => $this->detailService->adminDetailUrl('performer', ['user_id' => $row['user_id']]),
            ]))
            ->all();

        return $ops;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function serializeCampaignsForRealtime($workspace): array
    {
        return $this->campaignService->campaignsWithStats($workspace)
            ->take(6)
            ->map(fn ($campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'leads_count' => (int) ($campaign->leads_count ?? 0),
                'imports_count' => (int) ($campaign->imports_count ?? 0),
                'enriched_count' => (int) ($campaign->enriched_count ?? 0),
                'assigned_count' => (int) ($campaign->assigned_count ?? 0),
                'show_url' => route('admin.campaigns.show', $campaign),
            ])
            ->values()
            ->all();
    }

    protected function getDashboardData($workspace): array
    {
        $workflowIds = $workspace->workflows()->pluck('id')->all();
        $pipeline = $this->pipelineMetrics->pipelineCounts($workspace);
        $conversion = $this->pipelineMetrics->conversionRates($pipeline);
        $closedWon = $pipeline['closed_won'];

        $setters = $workspace->users()
            ->wherePivot('role', 'appointment_setter')
            ->get()
            ->map(function ($user) use ($workflowIds) {
                $leadsCount = WorkflowLead::whereIn('workflow_id', $workflowIds)
                    ->where('assigned_setter_id', $user->id)
                    ->whereIn('pipeline_phase', ['with_setter', 'appointment_settled', 'with_closer', 'closed'])
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

        $closers = $workspace->users()
            ->wherePivotIn('role', ['closer', 'closers_team_lead'])
            ->get()
            ->map(function ($user) use ($workspace) {
                $dealsClosed = $this->pipelineMetrics
                    ->scopeClosedWon($this->pipelineMetrics->workspaceQuery($workspace))
                    ->where('assigned_closer_id', $user->id)
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
                'total_leads' => $pipeline['total_leads'],
                'new' => $pipeline['new'],
                'qualified' => $pipeline['qualified'],
                'booked' => $pipeline['booked'],
                'showed' => $pipeline['showed'],
                'closed_won' => $pipeline['closed_won'],
                'not_now' => $pipeline['not_now'],
                'dead' => $pipeline['dead'],
            ],
            'conversion_rates' => array_merge($conversion, [
                'avg_closed_volume' => $this->pipelineMetrics->averageClosedVolume($workspace),
                'total_dials' => $this->pipelineMetrics->totalDials($workspace),
                'total_closes' => $closedWon,
            ]),
            'setters' => $setters,
            'closers' => $closers,
            'workflows' => $workspace->workflows()
                ->latest()
                ->get()
                ->map(function ($wf) {
                    $closedCount = WorkflowLead::where('workflow_id', $wf->id)
                        ->where(function ($q) {
                            $q->where(function ($inner) {
                                $inner->where('pipeline_phase', 'closed')
                                    ->where('closer_status', 'sale_made');
                            })->orWhere('stage', 'closed_won');
                        })
                        ->count();

                    $enrichedCount = WorkflowLead::where('workflow_id', $wf->id)
                        ->whereIn('pipeline_phase', ['enriched', 'with_setter', 'appointment_settled', 'with_closer', 'closed'])
                        ->whereIn('status', ['enriched', 'completed'])
                        ->count();

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
