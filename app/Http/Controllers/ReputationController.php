<?php

namespace App\Http\Controllers;

use App\Models\EmailList;
use App\Models\ReputationLog;
use App\Services\Deliverability\DeliverabilityAnalyzer;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReputationController extends Controller
{
    public function __construct(
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $logs = ReputationLog::query()
            ->where('workspace_id', $workspace->id)
            ->select(['id', 'domain', 'metric', 'value', 'notes', 'recorded_at'])
            ->latest('recorded_at')
            ->paginate(20);

        $hygiene = $this->buildHygieneStats($workspace->id);

        $warmupTarget = (int) $request->integer('warmup_target', 50000);
        $warmupWeeks = (int) $request->integer('warmup_weeks', 6);
        $warmupSchedule = $this->generateWarmupSchedule($warmupTarget, $warmupWeeks);

        return view('reputation.index', compact(
            'logs',
            'hygiene',
            'warmupSchedule',
            'warmupTarget',
            'warmupWeeks',
        ));
    }

    public function storeLog(Request $request)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $request->validate([
            'domain' => 'required|string|max:255',
            'metric' => 'required|string|max:100',
            'value' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'recorded_at' => 'required|date',
        ]);

        ReputationLog::create([
            ...$request->only(['domain', 'metric', 'value', 'notes', 'recorded_at']),
            'workspace_id' => $workspace->id,
            'user_id' => Auth::id(),
        ]);

        $route = request()->is('admin*') ? 'admin.reputation.index' : 'portal.reputation.index';

        return redirect()->route($route)->with('success', 'Reputation log saved.');
    }

    public function warmupCalculator(Request $request)
    {
        $request->validate([
            'target_daily' => 'required|integer|min:100|max:100000',
            'weeks' => 'required|integer|min:4|max:12',
        ]);

        $schedule = $this->generateWarmupSchedule(
            $request->integer('target_daily'),
            $request->integer('weeks')
        );

        return response()->json(['schedule' => $schedule]);
    }

    public function complianceCheck(Request $request, DeliverabilityAnalyzer $analyzer)
    {
        $workspace = $this->workspaceContext->resolveActiveWorkspace(Auth::user());

        $request->validate([
            'domain' => 'required|string|max:255',
        ]);

        $domain = strtolower($request->domain);
        $checklist = $this->buildComplianceChecklist($workspace->id, $domain, $analyzer);

        return response()->json([
            'domain' => $domain,
            'checklist' => $checklist,
        ]);
    }

    /**
     * @return array{total_lists: int, avg_invalid_rate: float, lists_needing_cleanup: int}
     */
    protected function buildHygieneStats(int $workspaceId): array
    {
        $base = EmailList::query()
            ->where('workspace_id', $workspaceId)
            ->where('total_count', '>', 0);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) as total_lists')
            ->selectRaw('AVG((invalid_count * 100.0) / total_count) as avg_invalid_rate')
            ->selectRaw('SUM(CASE WHEN invalid_count > total_count * 0.05 THEN 1 ELSE 0 END) as lists_needing_cleanup')
            ->first();

        return [
            'total_lists' => (int) ($stats->total_lists ?? 0),
            'avg_invalid_rate' => round((float) ($stats->avg_invalid_rate ?? 0), 2),
            'lists_needing_cleanup' => (int) ($stats->lists_needing_cleanup ?? 0),
        ];
    }

    /**
     * @return array<int, array{label: string, status: string, detail: string}>
     */
    protected function buildComplianceChecklist(int $workspaceId, string $domain, DeliverabilityAnalyzer $analyzer): array
    {
        $result = $analyzer->analyze($domain);
        $latestSpamLog = ReputationLog::query()
            ->where('workspace_id', $workspaceId)
            ->where('domain', $domain)
            ->where('metric', 'spam_rate')
            ->latest('recorded_at')
            ->first();

        $checklist = [
            $this->checklistItem('SPF record configured', $result['spf_result']['status'] ?? 'fail', $result['spf_result']['message'] ?? ''),
            $this->checklistItem('DKIM signing enabled', $result['dkim_result']['status'] ?? 'fail', $result['dkim_result']['message'] ?? ''),
            $this->checklistItem('DMARC record published', $result['dmarc_result']['status'] ?? 'fail', $result['dmarc_result']['message'] ?? ''),
            [
                'label' => 'One-click List-Unsubscribe header in emails',
                'status' => 'info',
                'detail' => 'Verify in your ESP template — cannot be checked from DNS alone.',
            ],
        ];

        if ($latestSpamLog) {
            $spamValue = strtolower((string) $latestSpamLog->value);
            $spamOk = ! str_contains($spamValue, 'high') && (float) preg_replace('/[^0-9.]/', '', $spamValue) < 0.1;
            $checklist[] = [
                'label' => 'Spam rate below 0.1% (from Postmaster log)',
                'status' => $spamOk ? 'pass' : 'warn',
                'detail' => 'Latest logged value: '.$latestSpamLog->value.' on '.$latestSpamLog->recorded_at->format('Y-m-d'),
            ];
        } else {
            $checklist[] = [
                'label' => 'Spam rate below 0.1% (monitor in Postmaster Tools)',
                'status' => 'warn',
                'detail' => 'No spam_rate logged yet for this domain.',
            ];
        }

        $checklist[] = [
            'label' => 'Never exceed 0.3% spam rate',
            'status' => 'info',
            'detail' => 'Google blocks mitigation above 0.3% — log weekly Postmaster metrics below.',
        ];

        $checklist[] = [
            'label' => 'Warm new domains 4–6 weeks before high volume',
            'status' => 'info',
            'detail' => 'Use the warmup calculator below to plan your ramp.',
        ];

        return $checklist;
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    protected function checklistItem(string $label, string $authStatus, string $detail): array
    {
        return [
            'label' => $label,
            'status' => in_array($authStatus, ['pass'], true) ? 'pass' : (in_array($authStatus, ['warn', 'skip'], true) ? 'warn' : 'fail'),
            'detail' => $detail,
        ];
    }

    protected function generateWarmupSchedule(int $targetDaily = 50000, int $weeks = 6): array
    {
        $schedule = [];
        $day = 1;

        $weeklyTargets = [];
        for ($w = 1; $w <= $weeks; $w++) {
            $weeklyTargets[$w] = (int) ($targetDaily * ($w / $weeks) ** 1.8);
        }

        for ($week = 1; $week <= $weeks; $week++) {
            $weekTarget = $weeklyTargets[$week];
            $startDay = $weeklyTargets[$week - 1] ?? 50;
            $daysInWeek = 7;

            for ($d = 0; $d < $daysInWeek; $d++) {
                $dailyVolume = (int) ($startDay + (($weekTarget - $startDay) / $daysInWeek) * ($d + 1));

                $schedule[] = [
                    'day' => $day,
                    'week' => $week,
                    'daily_volume' => max(50, $dailyVolume),
                    'focus' => $week <= 2 ? 'Most engaged subscribers only' : ($week <= 4 ? 'Monitor spam rate in Postmaster Tools' : 'Gradual scale to full list'),
                    'check' => $week >= 2 ? 'Keep spam rate below 0.1%' : 'Verify SPF, DKIM, DMARC first',
                ];
                $day++;
            }
        }

        return $schedule;
    }
}
