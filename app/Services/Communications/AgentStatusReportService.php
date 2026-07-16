<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AgentStatusReportService
{
    public function __construct(
        protected CallNotesHistoryService $notesHistory,
    ) {}

    /**
     * @return array{from: string, to: string, fromAt: Carbon, toAt: Carbon}
     */
    public function resolveDateRange(?string $from, ?string $to): array
    {
        $fromAt = filled($from)
            ? Carbon::parse($from)->startOfDay()
            : now()->startOfDay();
        $toAt = filled($to)
            ? Carbon::parse($to)->endOfDay()
            : now()->endOfDay();

        if ($fromAt->gt($toAt)) {
            [$fromAt, $toAt] = [$toAt->copy()->startOfDay(), $fromAt->copy()->endOfDay()];
        }

        return [
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
            'fromAt' => $fromAt,
            'toAt' => $toAt,
        ];
    }

    /**
     * @return Collection<int, array{id: int, name: string, email: string}>
     */
    public function dialerAgents(Workspace $workspace, User $viewer, string $tier): Collection
    {
        return $this->notesHistory->dialerAgents($workspace, $viewer, $tier);
    }

    /**
     * Talk-time / status rollup (disposition preferred, else call status).
     *
     * @return array<int, array{status: string, count: int, duration_sec: int, duration_label: string}>
     */
    public function statusSummary(Workspace $workspace, Carbon $fromAt, Carbon $toAt, ?int $userId = null, ?array $userIds = null): array
    {
        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$fromAt, $toAt]);

        $this->applyUserScope($query, $userId, $userIds);

        $rows = $query
            ->selectRaw("COALESCE(NULLIF(TRIM(disposition), ''), NULLIF(TRIM(status), ''), 'Unknown') as status_key")
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('COALESCE(SUM(COALESCE(duration_sec, 0)), 0) as total_sec')
            ->groupBy('status_key')
            ->orderByDesc('cnt')
            ->get();

        return $rows->map(function ($row) {
            $sec = (int) ($row->total_sec ?? 0);

            return [
                'status' => (string) ($row->status_key ?: 'Unknown'),
                'count' => (int) ($row->cnt ?? 0),
                'duration_sec' => $sec,
                'duration_label' => $this->formatDuration($sec),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function callLogRows(
        Workspace $workspace,
        Carbon $fromAt,
        Carbon $toAt,
        ?int $userId = null,
        ?array $userIds = null,
        int $limit = 500,
    ): array {
        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(max(1, min(2000, $limit)));

        $this->applyUserScope($query, $userId, $userIds);

        return $query->get()->map(function (CommunicationCallLog $log) {
            $status = trim((string) ($log->disposition ?: $log->status ?: 'Unknown')) ?: 'Unknown';
            $sec = (int) ($log->duration_sec ?? 0);

            return [
                'id' => $log->id,
                'agent' => (string) ($log->user?->name ?? '—'),
                'agent_email' => (string) ($log->user?->email ?? ''),
                'when' => optional($log->created_at)->timezone(config('app.timezone'))->format('M j, g:i A'),
                'when_exact' => optional($log->created_at)->toIso8601String(),
                'status' => $status,
                'duration_sec' => $sec,
                'duration_label' => $this->formatDuration($sec),
                'phone' => (string) ($log->to_phone ?: $log->from_phone ?: '—'),
                'direction' => (string) ($log->direction ?? ''),
            ];
        })->all();
    }

    public function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CommunicationCallLog>  $query
     */
    protected function applyUserScope($query, ?int $userId, ?array $userIds): void
    {
        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);

            return;
        }

        if ($userIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $userIds)));
            if ($ids === []) {
                $query->whereRaw('1 = 0');

                return;
            }
            $query->whereIn('user_id', $ids);
        }
    }
}
