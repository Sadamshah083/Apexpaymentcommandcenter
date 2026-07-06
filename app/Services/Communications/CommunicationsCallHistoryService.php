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
            ->limit(100)
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
            'from_phone' => $this->resolveOutboundCallerId($fromExtension),
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
        $fromPhone = $row->from_phone ?? $row->from_extension ?? '';
        $fromLabel = $row->from_extension
            ? (filled($fromPhone) && strlen(preg_replace('/\D/', '', $fromPhone) ?? '') >= 10
                ? "ext {$row->from_extension} ({$fromPhone})"
                : "{$agent} (ext {$row->from_extension})")
            : $agent;

        return [
            'id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
            'direction' => $row->direction,
            'from' => $fromLabel,
            'to' => $row->to_phone ?? '—',
            'from_phone' => $fromPhone,
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

    protected function resolveOutboundCallerId(string $fromExtension): ?string
    {
        $options = app(CommunicationsAgentService::class)->extensionDialOptions($fromExtension);
        $digits = $options['caller_id_number'] ?? null;

        if (! filled($digits)) {
            $configured = config('integrations.communications.default_outbound_did');
            $digits = filled($configured)
                ? preg_replace('/\D/', '', (string) $configured)
                : null;
        }

        if (! filled($digits)) {
            return null;
        }

        $digits = (string) $digits;

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits;
    }
}
