<?php

namespace App\Http\Controllers;

use App\Services\Communications\AgentStatusReportService;
use App\Services\Communications\CallRecordingSummaryService;
use App\Services\Communications\CommunicationsAccessService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AgentStatusReportController extends Controller
{
    public function __construct(
        protected AgentStatusReportService $report,
        protected CallRecordingSummaryService $callSummary,
        protected CommunicationsAccessService $access,
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewAllCallLogs($user, $routePrefix)) {
            abort(403, 'All call logs is not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        $tier = $this->access->tierFor($user, $routePrefix);
        $agents = $this->report->dialerAgents($workspace, $user, $tier);
        $agentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();

        $selectedAgentId = max(0, (int) $request->query('user_id', 0));

        // Agents may only review their own dispositions and recordings.
        if ($tier === 'agent') {
            $selectedAgentId = (int) $user->id;
        }

        if ($selectedAgentId > 0 && ! in_array($selectedAgentId, $agentIds, true) && ! in_array($tier, ['admin', 'supervisor', 'qa'], true)) {
            abort(403, 'You cannot view this agent.');
        }

        $range = $this->report->resolveDateRange(
            $request->query('from'),
            $request->query('to'),
        );

        $scopeUserIds = $selectedAgentId > 0
            ? null
            : (in_array($tier, ['admin', 'supervisor', 'qa'], true) ? null : $agentIds);
        $userId = $selectedAgentId > 0 ? $selectedAgentId : null;

        if ($tier === 'agent') {
            $userId = (int) $user->id;
            $scopeUserIds = null;
        }

        $phoneSearch = trim((string) $request->query('phone', ''));
        $dispositionChoice = trim((string) $request->query('disposition_choice', ''));
        $dispositionOther = trim((string) $request->query('disposition_other', ''));
        $selectedDisposition = trim((string) $request->query('disposition', ''));

        if ($dispositionChoice === '__other__') {
            $selectedDisposition = $dispositionOther;
        } elseif ($dispositionChoice !== '' && $dispositionChoice !== '__other__') {
            $selectedDisposition = $dispositionChoice;
        }

        $dispositionOptions = $this->report->availableDispositionLabels($workspace);

        $statusRows = $this->report->statusSummary(
            $workspace,
            $range['fromAt'],
            $range['toAt'],
            $userId,
            $scopeUserIds,
        );

        // Ensure labels seen in the current range appear in the dropdown even if rare.
        foreach ($statusRows as $row) {
            $label = trim((string) ($row['status'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            if (in_array($key, AgentStatusReportService::technicalTalkStatusKeys(), true)) {
                continue;
            }
            $exists = false;
            foreach ($dispositionOptions as $existing) {
                if (mb_strtolower($existing) === $key) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $dispositionOptions[] = $label;
            }
        }
        natcasesort($dispositionOptions);
        $dispositionOptions = array_values($dispositionOptions);

        if ($selectedDisposition !== '') {
            $key = mb_strtolower($selectedDisposition);
            if (in_array($key, AgentStatusReportService::technicalTalkStatusKeys(), true)) {
                $selectedDisposition = '';
            } else {
                $exists = false;
                foreach ($dispositionOptions as $existing) {
                    if (mb_strtolower($existing) === $key) {
                        $exists = true;
                        break;
                    }
                }
                if (! $exists) {
                    $dispositionOptions[] = $selectedDisposition;
                    natcasesort($dispositionOptions);
                    $dispositionOptions = array_values($dispositionOptions);
                }
            }
        }

        $dispositionChoiceValue = '';
        $showDispositionOther = false;
        if ($dispositionChoice === '__other__') {
            $dispositionChoiceValue = '__other__';
            $showDispositionOther = true;
        } elseif ($selectedDisposition !== '') {
            foreach ($dispositionOptions as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($selectedDisposition)) {
                    $dispositionChoiceValue = $existing;
                    break;
                }
            }
            if ($dispositionChoiceValue === '') {
                $dispositionChoiceValue = '__other__';
                $showDispositionOther = true;
            }
        }

        $callLogs = $this->report->paginatedCallLogRows(
            $workspace,
            $range['fromAt'],
            $range['toAt'],
            $userId,
            $scopeUserIds,
            max(1, (int) $request->query('logs_page', 1)),
            null,
            $phoneSearch,
            $selectedDisposition !== '' ? $selectedDisposition : null,
        );

        $totals = $this->report->callTotals(
            $workspace,
            $range['fromAt'],
            $range['toAt'],
            $userId,
            $scopeUserIds,
            $selectedDisposition !== '' ? $selectedDisposition : null,
        );
        $totalCalls = (int) ($totals['count'] ?? 0);
        $totalSec = (int) ($totals['duration_sec'] ?? 0);

        $queryBase = [
            'from' => $range['from'],
            'to' => $range['to'],
        ];
        if ($selectedAgentId > 0 && $tier !== 'agent') {
            $queryBase['user_id'] = $selectedAgentId;
        }
        if ($phoneSearch !== '') {
            $queryBase['phone'] = $phoneSearch;
        }
        if ($selectedDisposition !== '') {
            $queryBase['disposition'] = $selectedDisposition;
            if ($showDispositionOther) {
                $queryBase['disposition_choice'] = '__other__';
                $queryBase['disposition_other'] = $selectedDisposition;
            } else {
                $queryBase['disposition_choice'] = $dispositionChoiceValue;
            }
        }

        $view = $routePrefix === 'admin.'
            ? 'communications.agent-status.index'
            : 'communications.agent-status.portal';

        return view($view, [
            'routePrefix' => $routePrefix,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
            'viewerTier' => $tier,
            'agentOnlyView' => $tier === 'agent',
            'from' => $range['from'],
            'to' => $range['to'],
            'phoneSearch' => $phoneSearch,
            'selectedDisposition' => $selectedDisposition,
            'dispositionOptions' => $dispositionOptions,
            'dispositionChoiceValue' => $dispositionChoiceValue,
            'showDispositionOther' => $showDispositionOther,
            'statusRows' => $statusRows,
            'callLogs' => $callLogs,
            'totalCalls' => (int) $totalCalls,
            'totalDurationLabel' => $this->report->formatDuration((int) $totalSec),
            'exportUrl' => route($routePrefix.'communications.agent-status.export', $queryBase),
            'exportLogsUrl' => route($routePrefix.'communications.agent-status.export-logs', $queryBase),
            'exportSummariesUrl' => route($routePrefix.'communications.agent-status.export-summaries', $queryBase),
            'summaryUrl' => route($routePrefix.'communications.agent-status.summary'),
            'formUrl' => route($routePrefix.'communications.agent-status'),
            'monitoringUrl' => route($routePrefix.'communications.monitoring'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->streamExport($request, 'status');
    }

    public function exportLogs(Request $request): StreamedResponse
    {
        return $this->streamExport($request, 'logs');
    }

    /**
     * AI agent: generate a 3–4 line call recording summary.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewAllCallLogs($user, $routePrefix)) {
            abort(403, 'Call summaries are not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        $data = $request->validate([
            'call_log_ref' => 'nullable|string|max:120',
            'call_uuid' => 'nullable|string|max:120',
            'force' => 'sometimes|boolean',
            'instant' => 'sometimes|boolean',
        ]);

        if (blank($data['call_log_ref'] ?? null) && blank($data['call_uuid'] ?? null)) {
            return response()->json([
                'message' => 'Missing call reference.',
            ], 422);
        }

        $log = $this->callSummary->findLog(
            $workspace,
            $data['call_log_ref'] ?? null,
            $data['call_uuid'] ?? null,
        );

        if (! $log) {
            return response()->json([
                'message' => 'Call log not found.',
            ], 404);
        }

        $tier = $this->access->tierFor($user, $routePrefix);
        $agents = $this->report->dialerAgents($workspace, $user, $tier);
        $agentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->callSummary->assertViewerCanAccess($user, $log, $tier, $agentIds);

        $force = (bool) ($data['force'] ?? false);

        if ($force) {
            $this->callSummary->forgetCachedSummary((int) $log->id);
            // Also clear browser-side path by regenerating fresh AI below.
        }

        try {
            $payload = $this->callSummary->summarize(
                $workspace,
                $log,
                $force,
                true,
            );
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $isQuota = str_contains(strtolower($message), 'free-models-per-day')
                || str_contains(strtolower($message), 'insufficient credits')
                || str_contains(strtolower($message), 'rate limit')
                || str_contains(strtolower($message), 'unavailable for free');

            // Quota / model-availability noise should not flood Telescope.
            if (! $isQuota) {
                report($e);
            }

            return response()->json([
                'message' => $isQuota
                    ? 'AI summary is temporarily unavailable (OpenRouter quota/rate limit). Try again later or add credits.'
                    : 'Could not generate AI summary. Check OpenRouter configuration and try again.',
                'error' => config('app.debug') ? $message : null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'title' => 'AI call summary',
            ...$payload,
        ])->header('X-Summary-Cache', ! empty($payload['cached']) ? 'HIT' : 'MISS');
    }

    /**
     * Download all call summaries for the selected range (disposition, caller, numbers).
     */
    public function exportSummaries(Request $request): StreamedResponse
    {
        return $this->streamExport($request, 'summaries');
    }

    protected function streamExport(Request $request, string $mode): StreamedResponse
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewAllCallLogs($user, $routePrefix)) {
            abort(403, 'Export is not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        $tier = $this->access->tierFor($user, $routePrefix);
        $agents = $this->report->dialerAgents($workspace, $user, $tier);
        $agentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();

        $selectedAgentId = max(0, (int) $request->query('user_id', 0));
        if ($tier === 'agent') {
            $selectedAgentId = (int) $user->id;
        }
        if ($selectedAgentId > 0 && ! in_array($selectedAgentId, $agentIds, true) && ! in_array($tier, ['admin', 'supervisor', 'qa'], true)) {
            abort(403, 'You cannot export this agent.');
        }

        $range = $this->report->resolveDateRange(
            $request->query('from'),
            $request->query('to'),
        );

        $scopeUserIds = $selectedAgentId > 0
            ? null
            : (in_array($tier, ['admin', 'supervisor', 'qa'], true) ? null : $agentIds);
        $userId = $selectedAgentId > 0 ? $selectedAgentId : null;
        if ($tier === 'agent') {
            $userId = (int) $user->id;
            $scopeUserIds = null;
        }

        $selectedDisposition = trim((string) $request->query('disposition', ''));

        $filename = match ($mode) {
            'logs' => 'call-log-report-'.$range['from'].'_'.$range['to'].'.csv',
            'summaries' => 'call-summaries-'.$range['from'].'_'.$range['to'].'.csv',
            default => 'agent-talk-time-status-'.$range['from'].'_'.$range['to'].'.csv',
        };

        return response()->streamDownload(function () use ($mode, $workspace, $range, $userId, $scopeUserIds, $selectedDisposition) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($mode === 'summaries') {
                fputcsv($handle, [
                    'When',
                    'Caller (agent)',
                    'From number',
                    'Called number',
                    'Disposition',
                    'Duration',
                    'Summary',
                ]);
                $logs = $this->report->callLogModels(
                    $workspace,
                    $range['fromAt'],
                    $range['toAt'],
                    $userId,
                    $scopeUserIds,
                    5000,
                    $selectedDisposition !== '' ? $selectedDisposition : null,
                );
                foreach ($this->callSummary->bulkExportRows($logs) as $row) {
                    fputcsv($handle, [
                        $row['when'],
                        $row['agent'],
                        $row['from_phone'],
                        $row['to_phone'],
                        $row['status'],
                        $row['duration_label'],
                        preg_replace('/\*\*(.+?)\*\*/u', '$1', (string) $row['summary']),
                    ]);
                }
            } elseif ($mode === 'logs') {
                fputcsv($handle, ['Agent', 'When', 'Status', 'Duration', 'Phone', 'Direction', 'Recording']);
                foreach ($this->report->callLogRows(
                    $workspace,
                    $range['fromAt'],
                    $range['toAt'],
                    $userId,
                    $scopeUserIds,
                    5000,
                    $selectedDisposition !== '' ? $selectedDisposition : null,
                ) as $row) {
                    fputcsv($handle, [
                        $row['agent'],
                        $row['when_exact'] ?: $row['when'],
                        $row['status'],
                        $row['duration_label'],
                        $row['phone'],
                        $row['direction'],
                        ! empty($row['has_recording']) ? 'Yes' : ($row['recording_status'] ?? '—'),
                    ]);
                }
            } else {
                fputcsv($handle, ['Status', 'Count', 'Hours:MM:SS']);
                $statusRows = $this->report->statusSummary($workspace, $range['fromAt'], $range['toAt'], $userId, $scopeUserIds);
                $totalCalls = 0;
                $totalSec = 0;
                foreach ($statusRows as $row) {
                    $totalCalls += (int) $row['count'];
                    $totalSec += (int) $row['duration_sec'];
                    fputcsv($handle, [$row['status'], $row['count'], $row['duration_label']]);
                }
                fputcsv($handle, ['TOTAL CALLS', $totalCalls, $this->report->formatDuration($totalSec)]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function routePrefix(): string
    {
        return request()->is('admin*') || request()->routeIs('admin.*') ? 'admin.' : 'portal.';
    }
}
