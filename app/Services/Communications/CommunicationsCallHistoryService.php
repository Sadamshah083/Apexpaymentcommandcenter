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

    /**
     * Poll Morpheus CDR and update local call history after the dial completes.
     */
    public function syncFromCdr(CommunicationCallLog $log): void
    {
        if (! filled($log->morpheus_call_uuid)) {
            return;
        }

        $api = app(\App\Services\Integrations\ZoomApiService::class);
        $snap = null;

        for ($i = 0; $i < 8; $i++) {
            if ($i > 0) {
                sleep(5);
            }

            $snap = $api->getCall((string) $log->morpheus_call_uuid);
            if (! is_array($snap) || $snap === []) {
                continue;
            }

            if ($snap['live'] ?? false) {
                continue;
            }

            break;
        }

        if (! is_array($snap) || $snap === []) {
            return;
        }

        $cause = strtoupper((string) ($snap['hangup_cause'] ?? ''));
        $billsec = (int) ($snap['billsec'] ?? 0);
        $dest = (string) ($snap['destination_number'] ?? '');

        $status = match (true) {
            in_array($cause, ['USER_BUSY', 'CALL_REJECTED'], true) => 'busy',
            $billsec > 0 => 'completed',
            in_array($cause, ['NO_USER_RESPONSE', 'NO_ANSWER', 'ORIGINATOR_CANCEL'], true) => 'no_answer',
            default => 'completed',
        };

        $log->update([
            'status' => $status,
            'duration_sec' => $billsec > 0 ? $billsec : null,
            'ended_at' => now(),
            'meta' => array_merge($log->meta ?? [], [
                'cdr_destination' => $dest,
                'hangup_cause' => $cause,
                'cdr_synced_at' => now()->toIso8601String(),
                'cdr_has_recording' => (bool) ($snap['has_recording'] ?? data_get($snap, 'raw.has_recording')),
            ]),
        ]);

        $log = app(CommunicationsCallRecordingService::class)->resolveAndPersist($log->fresh());
        if ($log->recording_status === CommunicationsCallRecordingService::STATUS_PENDING) {
            $this->queueRecordingSync($log);
        }
    }

    protected function queueRecordingSync(CommunicationCallLog $log): void
    {
        if (! filled($log->morpheus_call_uuid)) {
            return;
        }

        \App\Jobs\SyncCallRecordingJob::dispatch($log->id)->afterResponse();
    }

    public function callNoteForRef(Workspace $workspace, string $callLogRef): string
    {
        if ($callLogRef === '') {
            return '';
        }

        $log = $this->resolveCallLog($workspace, $callLogRef);

        return (string) ($log?->note ?? '');
    }

    public function updateCallNote(Workspace $workspace, string $callLogRef, ?string $note, ?User $user = null): ?CommunicationCallLog
    {
        $log = $this->resolveCallLog($workspace, $callLogRef);
        if (! $log && $callLogRef !== '' && ! str_starts_with($callLogRef, 'local:')) {
            return $this->updateCallNoteByUuid($workspace, $callLogRef, $note, $user);
        }

        if (! $log) {
            return null;
        }

        $log->update([
            'note' => $note,
            'user_id' => $log->user_id ?: $user?->id,
        ]);

        return $log->fresh();
    }

    public function updateCallNoteByUuid(Workspace $workspace, string $uuid, ?string $note, ?User $user = null): ?CommunicationCallLog
    {
        $log = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('morpheus_call_uuid', $uuid)
            ->latest('id')
            ->first();

        if (! $log) {
            $log = CommunicationCallLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user?->id,
                'morpheus_call_uuid' => $uuid,
                'direction' => 'unknown',
                'note' => $note,
                'status' => 'initiated',
                'started_at' => now(),
                'meta' => ['source' => 'dialer_notes'],
            ]);

            return $log;
        }

        $log->update([
            'note' => $note,
            'user_id' => $log->user_id ?: $user?->id,
        ]);

        return $log->fresh();
    }

    public function resolveCallLog(Workspace $workspace, string $callLogRef): ?CommunicationCallLog
    {
        if (str_starts_with($callLogRef, 'local:')) {
            $id = (int) substr($callLogRef, 6);

            return CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereKey($id)
                ->first();
        }

        return CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('morpheus_call_uuid', $callLogRef)
            ->latest('id')
            ->first();
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

        $recording = app(CommunicationsCallRecordingService::class)->recordingFieldsForHubLog($row);

        return [
            'id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
            'direction' => $row->direction,
            'from' => $fromLabel,
            'to' => $row->to_phone ?? '—',
            'from_phone' => $fromPhone,
            'from_extension' => $row->from_extension,
            'to_phone' => $row->to_phone ?? '',
            'start_time' => ($row->started_at ?? $row->created_at)?->toIso8601String(),
            'result' => $row->disposition ?: ucfirst($row->status),
            'note' => $row->note,
            'duration' => $row->duration_sec ?? 0,
            'recording' => $recording['recording'],
            'has_recording_media' => $recording['has_recording_media'],
            'recording_id' => $recording['recording_id'],
            'recording_source' => $recording['recording_source'],
            'call_reference_id' => $recording['call_reference_id'],
            'recording_status' => $recording['recording_status'],
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
