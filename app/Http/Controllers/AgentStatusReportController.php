<?php

namespace App\Http\Controllers;

use App\Services\Communications\AgentStatusReportService;
use App\Services\Communications\CommunicationsAccessService;
use App\Services\Workspace\WorkspaceContextService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentStatusReportController extends Controller
{
    public function __construct(
        protected AgentStatusReportService $report,
        protected CommunicationsAccessService $access,
        protected WorkspaceContextService $workspaceContext,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
            abort(403, 'Agent status report is not available for this account.');
        }

        $workspace = $this->workspaceContext->resolveActiveWorkspace($user);
        if (! $workspace) {
            abort(404, 'Workspace not found.');
        }

        $tier = $this->access->tierFor($user, $routePrefix);
        $agents = $this->report->dialerAgents($workspace, $user, $tier);
        $agentIds = $agents->pluck('id')->map(fn ($id) => (int) $id)->all();

        $selectedAgentId = max(0, (int) $request->query('user_id', 0));
        if ($selectedAgentId > 0 && ! in_array($selectedAgentId, $agentIds, true) && $tier !== 'admin') {
            abort(403, 'You cannot view this agent.');
        }

        $range = $this->report->resolveDateRange(
            $request->query('from'),
            $request->query('to'),
        );

        $scopeUserIds = $selectedAgentId > 0 ? null : ($tier === 'admin' || $tier === 'supervisor' ? null : $agentIds);
        $userId = $selectedAgentId > 0 ? $selectedAgentId : null;

        $statusRows = $this->report->statusSummary(
            $workspace,
            $range['fromAt'],
            $range['toAt'],
            $userId,
            $scopeUserIds,
        );
        $callLogs = $this->report->callLogRows(
            $workspace,
            $range['fromAt'],
            $range['toAt'],
            $userId,
            $scopeUserIds,
            400,
        );

        $totalCalls = collect($statusRows)->sum('count');
        $totalSec = collect($statusRows)->sum('duration_sec');

        $queryBase = [
            'from' => $range['from'],
            'to' => $range['to'],
        ];
        if ($selectedAgentId > 0) {
            $queryBase['user_id'] = $selectedAgentId;
        }

        $view = $routePrefix === 'admin.'
            ? 'communications.agent-status.index'
            : 'communications.agent-status.portal';

        return view($view, [
            'routePrefix' => $routePrefix,
            'agents' => $agents,
            'selectedAgentId' => $selectedAgentId,
            'from' => $range['from'],
            'to' => $range['to'],
            'statusRows' => $statusRows,
            'callLogs' => $callLogs,
            'totalCalls' => (int) $totalCalls,
            'totalDurationLabel' => $this->report->formatDuration((int) $totalSec),
            'exportUrl' => route($routePrefix.'communications.agent-status.export', $queryBase),
            'exportLogsUrl' => route($routePrefix.'communications.agent-status.export-logs', $queryBase),
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

    protected function streamExport(Request $request, string $mode): StreamedResponse
    {
        $user = $request->user();
        $routePrefix = $this->routePrefix();

        if (! $this->access->canViewCallMonitoring($user, $routePrefix)) {
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
        if ($selectedAgentId > 0 && ! in_array($selectedAgentId, $agentIds, true) && $tier !== 'admin') {
            abort(403, 'You cannot export this agent.');
        }

        $range = $this->report->resolveDateRange(
            $request->query('from'),
            $request->query('to'),
        );

        $scopeUserIds = $selectedAgentId > 0 ? null : ($tier === 'admin' || $tier === 'supervisor' ? null : $agentIds);
        $userId = $selectedAgentId > 0 ? $selectedAgentId : null;

        $filename = $mode === 'logs'
            ? 'call-log-report-'.$range['from'].'_'.$range['to'].'.csv'
            : 'agent-talk-time-status-'.$range['from'].'_'.$range['to'].'.csv';

        return response()->streamDownload(function () use ($mode, $workspace, $range, $userId, $scopeUserIds) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            if ($mode === 'logs') {
                fputcsv($handle, ['Agent', 'When', 'Status', 'Duration', 'Phone', 'Direction']);
                foreach ($this->report->callLogRows($workspace, $range['fromAt'], $range['toAt'], $userId, $scopeUserIds, 5000) as $row) {
                    fputcsv($handle, [
                        $row['agent'],
                        $row['when_exact'] ?: $row['when'],
                        $row['status'],
                        $row['duration_label'],
                        $row['phone'],
                        $row['direction'],
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
