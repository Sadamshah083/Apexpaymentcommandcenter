<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CommunicationsCallHistoryService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForHub(Workspace $workspace, array $filters = []): array
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(14)->startOfDay();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();

        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn (CommunicationCallLog $row) => $this->toHubLog($row))
            ->all();
    }

    public function logOutboundDial(
        Workspace $workspace,
        User $user,
        string $fromExtension,
        string $destination,
        ?string $morpheusCallUuid = null,
    ): CommunicationCallLog {
        return CommunicationCallLog::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'morpheus_call_uuid' => $morpheusCallUuid,
            'direction' => 'outbound',
            'from_extension' => $fromExtension,
            'to_phone' => $destination,
            'status' => 'initiated',
            'started_at' => now(),
            'meta' => ['source' => 'hub_dialer'],
        ]);
    }

    public function recordDisposition(
        Workspace $workspace,
        string $uuid,
        string $disposition,
        ?string $note,
        ?User $user = null,
    ): void {
        $log = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('morpheus_call_uuid', $uuid)
            ->latest('id')
            ->first();

        if (! $log) {
            CommunicationCallLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user?->id,
                'morpheus_call_uuid' => $uuid,
                'direction' => 'unknown',
                'disposition' => $disposition,
                'note' => $note,
                'status' => 'completed',
                'ended_at' => now(),
                'meta' => ['source' => 'morpheus_disposition'],
            ]);

            return;
        }

        $log->update([
            'disposition' => $disposition,
            'note' => $note,
            'status' => 'completed',
            'ended_at' => now(),
            'duration_sec' => $log->started_at ? (int) $log->started_at->diffInSeconds(now()) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function toHubLog(CommunicationCallLog $row): array
    {
        $agent = $row->user?->name ?? 'Agent';

        return [
            'id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
            'direction' => $row->direction,
            'from' => $row->from_extension ? "{$agent} (ext {$row->from_extension})" : $agent,
            'to' => $row->to_phone ?? '—',
            'from_phone' => $row->from_phone ?? $row->from_extension ?? '',
            'to_phone' => $row->to_phone ?? '',
            'start_time' => ($row->started_at ?? $row->created_at)?->toIso8601String(),
            'result' => $row->disposition ?: ucfirst($row->status),
            'duration' => $row->duration_sec ?? 0,
            'recording' => '—',
            'campaign_id' => null,
            'source' => 'local_history',
            'raw' => $row->toArray(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $liveLogs
     * @param  array<int, array<string, mixed>>  $historyLogs
     * @return array<int, array<string, mixed>>
     */
    public function mergeLiveAndHistory(array $liveLogs, array $historyLogs): array
    {
        $liveUuids = collect($liveLogs)->pluck('id')->filter()->all();

        $merged = collect($liveLogs)
            ->concat(
                collect($historyLogs)->reject(
                    fn (array $row) => in_array($row['id'], $liveUuids, true)
                )
            )
            ->sortByDesc(fn (array $row) => $row['start_time'] ?? '')
            ->values()
            ->all();

        return $merged;
    }
}
