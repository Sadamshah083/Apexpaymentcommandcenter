<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\LeadDisposition;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AgentStatusReportService
{
    public function __construct(
        protected CallNotesHistoryService $notesHistory,
        protected CommunicationsCallRecordingService $recordings,
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
     * Hides raw telephony pipeline states that are not agent dispositions.
     *
     * @return array<int, array{status: string, count: int, duration_sec: int, duration_label: string}>
     */
    public function statusSummary(Workspace $workspace, Carbon $fromAt, Carbon $toAt, ?int $userId = null, ?array $userIds = null): array
    {
        $ttl = max(15, (int) config('pagination.agent_status_cache_ttl', 60));
        $cacheKey = $this->cacheKey('status', $workspace->id, $fromAt, $toAt, $userId, $userIds);

        return Cache::remember($cacheKey, $ttl, function () use ($workspace, $fromAt, $toAt, $userId, $userIds) {
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

            $hidden = self::technicalTalkStatusKeys();

            return $rows
                ->filter(function ($row) use ($hidden) {
                    $key = strtolower(trim((string) ($row->status_key ?: '')));

                    return $key === '' || ! in_array($key, $hidden, true);
                })
                ->map(function ($row) {
                    $sec = (int) ($row->total_sec ?? 0);

                    return [
                        'status' => (string) ($row->status_key ?: 'Unknown'),
                        'count' => (int) ($row->cnt ?? 0),
                        'duration_sec' => $sec,
                        'duration_label' => $this->formatDuration($sec),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * Overall call count / talk seconds for the selected range (includes all statuses).
     *
     * @return array{count: int, duration_sec: int}
     */
    public function callTotals(
        Workspace $workspace,
        Carbon $fromAt,
        Carbon $toAt,
        ?int $userId = null,
        ?array $userIds = null,
        ?string $disposition = null,
    ): array {
        $ttl = max(15, (int) config('pagination.agent_status_cache_ttl', 60));
        $dispositionKey = $this->normalizeDispositionFilter($disposition);
        $cacheKey = $this->cacheKey('totals', $workspace->id, $fromAt, $toAt, $userId, $userIds, 0, 0, '', '', $dispositionKey);

        return Cache::remember($cacheKey, $ttl, function () use ($workspace, $fromAt, $toAt, $userId, $userIds, $dispositionKey) {
            $query = CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereBetween('created_at', [$fromAt, $toAt]);
            $this->applyUserScope($query, $userId, $userIds);
            $this->applyDispositionFilter($query, $dispositionKey);

            $row = $query->selectRaw('COUNT(*) as cnt, COALESCE(SUM(duration_sec), 0) as duration_sec')->first();

            return [
                'count' => (int) ($row->cnt ?? 0),
                'duration_sec' => (int) ($row->duration_sec ?? 0),
            ];
        });
    }

    /**
     * Raw call-pipeline statuses — not agent dispositions (Talk time table).
     *
     * @return list<string>
     */
    public static function technicalTalkStatusKeys(): array
    {
        return [
            'initiated',
            'connected',
            'ringing',
            'bridging',
            'active',
            'talking',
            'answered',
            'ended',
            'no-answer',
            'completed',
            'busy',
            'failed',
            'canceled',
            'cancelled',
            'missed',
            'unknown',
            'hangup',
            'hangup_cause',
        ];
    }

    /**
     * Agent disposition label for Status column — never show CDR pipeline states
     * like completed / initiated.
     */
    public function resolveDisplayDisposition(?string $disposition, ?string $status = null): string
    {
        $hidden = array_map('strtolower', self::technicalTalkStatusKeys());

        foreach ([$disposition, $status] as $candidate) {
            $value = trim((string) ($candidate ?? ''));
            if ($value === '' || $value === '—' || $value === '-') {
                continue;
            }

            $key = mb_strtolower($value);
            if (in_array($key, $hidden, true)) {
                continue;
            }

            return $value;
        }

        return '—';
    }

    public static function forgetCachesForWorkspace(int $workspaceId): void
    {
        // Cache keys include timestamps/scopes; flush tagged pattern via version bump.
        $versionKey = 'agent_status:cache_version:w'.$workspaceId;
        Cache::forever($versionKey, (int) Cache::get($versionKey, 1) + 1);
    }

    /**
     * Paginated call log rows for the All call logs table (cached per page).
     */
    public function paginatedCallLogRows(
        Workspace $workspace,
        Carbon $fromAt,
        Carbon $toAt,
        ?int $userId = null,
        ?array $userIds = null,
        int $page = 1,
        ?int $perPage = null,
        ?string $phoneSearch = null,
        ?string $disposition = null,
    ): LengthAwarePaginator {
        $perPage = max(5, min(100, $perPage ?? (int) config('pagination.agent_status_logs_per_page', 25)));
        $page = max(1, $page);
        $phoneDigits = $this->normalizePhoneSearch($phoneSearch);
        $dispositionKey = $this->normalizeDispositionFilter($disposition);
        $ttl = max(15, (int) config('pagination.agent_status_cache_ttl', 60));
        $routePrefix = request()->is('admin*') || request()->routeIs('admin.*') ? 'admin' : 'portal';
        $cacheKey = $this->cacheKey(
            'logs',
            $workspace->id,
            $fromAt,
            $toAt,
            $userId,
            $userIds,
            $page,
            $perPage,
            $routePrefix,
            $phoneDigits,
            $dispositionKey,
        );

        $payload = Cache::remember($cacheKey, $ttl, function () use ($workspace, $fromAt, $toAt, $userId, $userIds, $page, $perPage, $phoneDigits, $dispositionKey) {
            $base = CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereBetween('created_at', [$fromAt, $toAt]);
            $this->applyUserScope($base, $userId, $userIds);
            $this->applyPhoneSearch($base, $phoneDigits);
            $this->applyDispositionFilter($base, $dispositionKey);

            $total = (clone $base)->count();
            $rows = (clone $base)
                ->with('user:id,name,email')
                ->select([
                    'id',
                    'workspace_id',
                    'user_id',
                    'morpheus_call_uuid',
                    'direction',
                    'from_phone',
                    'to_phone',
                    'disposition',
                    'status',
                    'duration_sec',
                    'recording_file_id',
                    'recording_source',
                    'recording_status',
                    'meta',
                    'created_at',
                ])
                ->orderByDesc('created_at')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn (CommunicationCallLog $log) => $this->mapCallLogRow($log))
                ->all();

            return [
                'total' => $total,
                'rows' => $rows,
            ];
        });

        return new Paginator(
            $payload['rows'],
            (int) $payload['total'],
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
                'pageName' => 'logs_page',
            ]
        );
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
        ?string $disposition = null,
    ): array {
        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->with('user:id,name,email')
            ->select([
                'id',
                'workspace_id',
                'user_id',
                'morpheus_call_uuid',
                'direction',
                'from_phone',
                'to_phone',
                'disposition',
                'status',
                'duration_sec',
                'recording_file_id',
                'recording_source',
                'recording_status',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->limit(max(1, min(2000, $limit)));

        $this->applyUserScope($query, $userId, $userIds);
        $this->applyDispositionFilter($query, $this->normalizeDispositionFilter($disposition));

        return $query->get()->map(fn (CommunicationCallLog $log) => $this->mapCallLogRow($log))->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, CommunicationCallLog>
     */
    public function callLogModels(
        Workspace $workspace,
        Carbon $fromAt,
        Carbon $toAt,
        ?int $userId = null,
        ?array $userIds = null,
        int $limit = 5000,
        ?string $disposition = null,
    ) {
        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$fromAt, $toAt])
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->limit(max(1, min(5000, $limit)));

        $this->applyUserScope($query, $userId, $userIds);
        $this->applyDispositionFilter($query, $this->normalizeDispositionFilter($disposition));

        return $query->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapCallLogRow(CommunicationCallLog $log): array
    {
        $status = $this->resolveDisplayDisposition($log->disposition, $log->status);
        $sec = (int) ($log->duration_sec ?? 0);
        $recording = $this->recordings->recordingFieldsForHubLog($log);
        $hasRecording = ! empty($recording['has_recording_media']) && ! empty($recording['recording_id']);
        $callRef = (string) ($recording['call_reference_id'] ?? ('local:'.$log->id));
        $routePrefix = request()->is('admin*') || request()->routeIs('admin.*') ? 'admin.' : 'portal.';
        $playUrl = $hasRecording
            ? route($routePrefix.'communications.zoom.recordings.media', [
                'recordingId' => $recording['recording_id'],
                'source' => $recording['recording_source'] ?? 'morpheus',
                'action' => 'play',
                'call_ref' => $callRef,
            ])
            : '';
        $downloadUrl = $hasRecording
            ? route($routePrefix.'communications.zoom.recordings.media', [
                'recordingId' => $recording['recording_id'],
                'source' => $recording['recording_source'] ?? 'morpheus',
                'action' => 'download',
                'call_ref' => $callRef,
            ])
            : '';

        $meta = is_array($log->meta) ? $log->meta : [];
        $rawSummary = trim((string) ($meta['ai_call_summary'] ?? ''));
        $hasAiSummary = app(CallRecordingSummaryService::class)->isValidCachedSummary($rawSummary);
        $fromPhone = (string) ($log->from_phone ?: '');
        $toPhone = (string) ($log->to_phone ?: '');

        return [
            'id' => $log->id,
            'agent' => (string) ($log->user?->name ?? '—'),
            'agent_email' => (string) ($log->user?->email ?? ''),
            'when' => optional($log->created_at)->timezone(config('app.timezone'))->format('M j, g:i A'),
            'when_exact' => optional($log->created_at)->toIso8601String(),
            'status' => $status,
            'duration_sec' => $sec,
            'duration_label' => $this->formatDuration($sec),
            'phone' => $toPhone !== '' ? $toPhone : ($fromPhone !== '' ? $fromPhone : '—'),
            'from_phone' => $fromPhone !== '' ? $fromPhone : '—',
            'to_phone' => $toPhone !== '' ? $toPhone : '—',
            'direction' => (string) ($log->direction ?? ''),
            'recording_status' => (string) ($recording['recording_status'] ?? 'none'),
            'has_recording' => $hasRecording,
            'recording_id' => $recording['recording_id'] ?? null,
            'play_url' => $playUrl,
            'download_url' => $downloadUrl,
            'call_log_ref' => 'local:'.$log->id,
            'call_uuid' => (string) ($log->morpheus_call_uuid ?? ''),
            'has_ai_summary' => $hasAiSummary,
            'ai_summary' => $hasAiSummary ? $rawSummary : '',
        ];
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

    protected function normalizePhoneSearch(?string $phoneSearch): string
    {
        return preg_replace('/\D+/', '', trim((string) $phoneSearch)) ?? '';
    }

    /**
     * Distinct agent disposition labels known to the system (not hardcoded).
     * Merges call-log dispositions + lead disposition history for the workspace.
     *
     * @return list<string>
     */
    public function availableDispositionLabels(Workspace $workspace): array
    {
        $hidden = self::technicalTalkStatusKeys();
        $labels = [];

        $fromLogs = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('disposition')
            ->where('disposition', '!=', '')
            ->distinct()
            ->orderBy('disposition')
            ->pluck('disposition');

        foreach ($fromLogs as $label) {
            $trimmed = trim((string) $label);
            if ($trimmed === '') {
                continue;
            }
            $key = mb_strtolower($trimmed);
            if (in_array($key, $hidden, true)) {
                continue;
            }
            $labels[$key] = $trimmed;
        }

        if (Schema::hasTable('lead_dispositions')) {
            $fromLeads = LeadDisposition::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('disposition')
                ->where('disposition', '!=', '')
                ->distinct()
                ->orderBy('disposition')
                ->pluck('disposition');

            foreach ($fromLeads as $label) {
                $trimmed = trim((string) $label);
                if ($trimmed === '') {
                    continue;
                }
                $key = mb_strtolower($trimmed);
                if (in_array($key, $hidden, true)) {
                    continue;
                }
                if (! isset($labels[$key])) {
                    $labels[$key] = $trimmed;
                }
            }
        }

        $sorted = array_values($labels);
        natcasesort($sorted);

        return array_values($sorted);
    }

    protected function normalizeDispositionFilter(?string $disposition): string
    {
        return trim((string) $disposition);
    }

    /**
     * Filter by displayed Status (disposition preferred, else call status).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CommunicationCallLog>  $query
     */
    protected function applyDispositionFilter($query, string $disposition): void
    {
        if ($disposition === '') {
            return;
        }

        $needle = mb_strtolower($disposition);
        $query->whereRaw(
            "LOWER(TRIM(COALESCE(NULLIF(TRIM(disposition), ''), NULLIF(TRIM(status), ''), 'Unknown'))) = ?",
            [$needle]
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\CommunicationCallLog>  $query
     */
    protected function applyPhoneSearch($query, string $phoneDigits): void
    {
        if ($phoneDigits === '') {
            return;
        }

        // Match digits anywhere in stored from/to numbers (supports partial search).
        $like = '%'.$phoneDigits.'%';
        $query->where(function ($inner) use ($like) {
            $inner->where('to_phone', 'like', $like)
                ->orWhere('from_phone', 'like', $like);
        });
    }

    /**
     * @param  list<int>|null  $userIds
     */
    protected function cacheKey(
        string $kind,
        int $workspaceId,
        Carbon $fromAt,
        Carbon $toAt,
        ?int $userId,
        ?array $userIds,
        int $page = 0,
        int $perPage = 0,
        string $routePrefix = '',
        string $phoneDigits = '',
        string $disposition = '',
    ): string {
        $scope = $userId && $userId > 0
            ? 'u'.$userId
            : 's'.md5(json_encode(array_values(array_map('intval', $userIds ?? []))));

        return sprintf(
            'agent_status:v%d:r2:%s:w%d:%s:%s:%s:p%d:n%d:%s:ph%s:d%s',
            (int) Cache::get('agent_status:cache_version:w'.$workspaceId, 1),
            $kind,
            $workspaceId,
            $fromAt->timestamp,
            $toAt->timestamp,
            $scope,
            $page,
            $perPage,
            $routePrefix !== '' ? $routePrefix : 'any',
            $phoneDigits !== '' ? md5($phoneDigits) : 'all',
            $disposition !== '' ? md5(mb_strtolower($disposition)) : 'all',
        );
    }
}
