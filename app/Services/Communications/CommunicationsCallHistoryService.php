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

        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->whereBetween('created_at', [$from, $to])
            ->with('user:id,name');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        } elseif (! empty($filters['from_extension'])) {
            $ext = preg_replace('/\D/', '', (string) $filters['from_extension']) ?: (string) $filters['from_extension'];
            if ($ext !== '') {
                $query->where('from_extension', $ext);
            }
        }

        $limit = ! empty($filters['user_id']) ? 200 : 100;

        return $query
            ->orderByDesc('created_at')
            ->limit($limit)
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

    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<string, string>
     */
    public function mapCallNotesForRefs(Workspace $workspace, array $logs): array
    {
        $uuids = [];
        $localIds = [];

        foreach ($logs as $log) {
            $id = (string) ($log['id'] ?? '');
            if ($id === '') {
                continue;
            }

            if (str_starts_with($id, 'local:')) {
                $localIds[] = (int) substr($id, 6);
            } else {
                $uuids[] = $id;
            }
        }

        $notesByRef = [];

        if ($localIds !== []) {
            CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', array_values(array_unique($localIds)))
                ->get()
                ->each(function (CommunicationCallLog $row) use (&$notesByRef) {
                    // Always map the row (even empty) so callers know this call was resolved
                    // and do not fall back to a shared phone/contact note.
                    $note = trim((string) ($row->note ?? ''));
                    $notesByRef['local:'.$row->id] = $note;
                    if (filled($row->morpheus_call_uuid)) {
                        $notesByRef[(string) $row->morpheus_call_uuid] = $note;
                    }
                });
        }

        if ($uuids !== []) {
            CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('morpheus_call_uuid', array_values(array_unique($uuids)))
                ->orderByDesc('id')
                ->get()
                ->groupBy('morpheus_call_uuid')
                ->each(function ($rows, $uuid) use (&$notesByRef) {
                    if (isset($notesByRef[(string) $uuid])) {
                        return;
                    }

                    $note = trim((string) ($rows->first()?->note ?? ''));
                    $notesByRef[(string) $uuid] = $note;
                });
        }

        return $notesByRef;
    }

    public function resolveCallNoteForHubLog(array $log, array $notesByRef = []): string
    {
        $callLogRef = (string) ($log['id'] ?? '');

        // Exact per-call match only (including empty) — never reuse another call's comment.
        if ($callLogRef !== '' && array_key_exists($callLogRef, $notesByRef)) {
            return trim((string) $notesByRef[$callLogRef]);
        }

        // Local history rows already carry their own DB note for this call id.
        if (($log['source'] ?? '') === 'local_history') {
            return trim((string) ($log['note'] ?? $log['call_note'] ?? ''));
        }

        // Do not fall back to Morpheus/raw/contact notes — those repeat for the same phone number.
        return '';
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
        $this->recordDialerDisposition($workspace, [
            'call_uuid' => $uuid,
            'disposition' => $disposition,
            'note' => $note,
            'user' => $user,
        ]);
    }

    /**
     * Persist disposition onto call history so it appears in dialer Call logs.
     *
     * @param  array{call_uuid?: ?string, phone?: ?string, disposition: string, note?: ?string, duration_sec?: ?int, user?: ?User, lead_id?: ?int}  $payload
     */
    public function recordDialerDisposition(Workspace $workspace, array $payload): CommunicationCallLog
    {
        $disposition = trim((string) ($payload['disposition'] ?? ''));
        // Keep comment separate from disposition so Call logs can show both cleanly.
        $historyNote = filled($payload['note'] ?? null) ? trim((string) $payload['note']) : null;
        $uuid = trim((string) ($payload['call_uuid'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $durationSec = isset($payload['duration_sec']) ? max(0, (int) $payload['duration_sec']) : null;
        /** @var User|null $user */
        $user = $payload['user'] ?? null;
        $leadId = isset($payload['lead_id']) ? (int) $payload['lead_id'] : null;

        $log = null;
        if ($uuid !== '') {
            $log = CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('morpheus_call_uuid', $uuid)
                ->latest('id')
                ->first();
        }

        if (! $log && $phone !== '') {
            $log = CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('to_phone', $phone)
                ->where('direction', 'outbound')
                ->where(function ($query) {
                    $query->whereNull('disposition')
                        ->orWhere('disposition', '');
                })
                ->where('created_at', '>=', now()->subHours(6))
                ->latest('id')
                ->first();
        }

        $callResult = ($durationSec ?? 0) > 0 ? 'connected' : 'no-answer';
        $inCallNotes = filled($payload['in_call_notes'] ?? null) ? trim((string) $payload['in_call_notes']) : null;
        $meta = [
            'source' => 'dialer_disposition',
            'lead_id' => $leadId ?: null,
            'call_result' => $callResult,
        ];
        if ($disposition !== '') {
            $meta['disposition'] = $disposition;
        }
        if ($inCallNotes !== null && $inCallNotes !== '') {
            $meta['in_call_notes'] = $inCallNotes;
        }

        if (! $log) {
            return CommunicationCallLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user?->id,
                'morpheus_call_uuid' => $uuid !== '' ? $uuid : null,
                'direction' => 'outbound',
                'to_phone' => $phone !== '' ? $phone : null,
                'disposition' => $disposition !== '' ? $disposition : null,
                'note' => $historyNote !== '' && $historyNote !== null ? $historyNote : null,
                'status' => 'completed',
                'duration_sec' => $durationSec,
                'started_at' => $durationSec ? now()->subSeconds($durationSec) : now(),
                'ended_at' => now(),
                'meta' => $meta,
            ]);
        }

        $log->update([
            'disposition' => $disposition !== '' ? $disposition : $log->disposition,
            'note' => $historyNote !== null && $historyNote !== '' ? $historyNote : $log->note,
            'status' => 'completed',
            'ended_at' => now(),
            'duration_sec' => $durationSec ?? $log->duration_sec ?? ($log->started_at ? (int) $log->started_at->diffInSeconds(now()) : null),
            'to_phone' => $log->to_phone ?: ($phone !== '' ? $phone : null),
            'morpheus_call_uuid' => $log->morpheus_call_uuid ?: ($uuid !== '' ? $uuid : null),
            'user_id' => $log->user_id ?: $user?->id,
            'meta' => array_merge($log->meta ?? [], $meta),
        ]);

        return $log->fresh() ?? $log;
    }

    /**
     * @return array<string, mixed>
     */
    public function toHubLogPublic(CommunicationCallLog $row): array
    {
        return $this->toHubLog($row);
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

        $callResult = data_get($row->meta, 'call_result');
        if (! filled($callResult)) {
            $callResult = ($row->duration_sec ?? 0) > 0 ? 'connected' : ucfirst((string) ($row->status ?: 'completed'));
        }

        return [
            'id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
            'direction' => $row->direction,
            'from' => $fromLabel,
            'to' => $row->to_phone ?? '—',
            'from_phone' => $fromPhone,
            'from_extension' => $row->from_extension,
            'to_phone' => $row->to_phone ?? '',
            'user_id' => $row->user_id,
            'agent_name' => $agent,
            'start_time' => ($row->started_at ?? $row->created_at)?->toIso8601String(),
            'result' => $callResult,
            'disposition' => filled($row->disposition)
                ? $row->disposition
                : (trim((string) data_get($row->meta, 'disposition', '')) ?: null),
            'note' => $row->note,
            'in_call_notes' => trim((string) data_get($row->meta, 'in_call_notes', '')),
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
        $historyById = collect($historyLogs)
            ->filter(fn (array $row) => filled($row['id'] ?? null))
            ->keyBy(fn (array $row) => (string) $row['id']);

        $liveUuids = collect($liveLogs)->pluck('id')->filter()->map(fn ($id) => (string) $id)->all();

        // Overlay hub-saved disposition / notes onto matching live CDR rows so Call logs keep them.
        $liveMerged = collect($liveLogs)->map(function (array $live) use ($historyById) {
            $id = (string) ($live['id'] ?? '');
            if ($id === '' || ! $historyById->has($id)) {
                return $live;
            }

            $history = $historyById->get($id);
            $historyDisposition = trim((string) ($history['disposition'] ?? ''));
            $historyNote = trim((string) ($history['note'] ?? $history['call_note'] ?? ''));
            $historyInCallNotes = trim((string) ($history['in_call_notes'] ?? data_get($history, 'raw.meta.in_call_notes') ?? ''));

            if ($historyDisposition !== '') {
                $live['disposition'] = $historyDisposition;
            }
            if ($historyNote !== '') {
                $live['note'] = $historyNote;
                $live['call_note'] = $historyNote;
            }
            if ($historyInCallNotes !== '') {
                $live['in_call_notes'] = $historyInCallNotes;
            }

            // Prefer local recording fields when live CDR lacks them.
            if (empty($live['has_recording_media']) && ! empty($history['has_recording_media'])) {
                foreach (['has_recording_media', 'recording_id', 'recording_source', 'call_reference_id', 'recording_status', 'recording'] as $key) {
                    if (array_key_exists($key, $history)) {
                        $live[$key] = $history[$key];
                    }
                }
            }

            return $live;
        })->all();

        $merged = collect($liveMerged)
            ->concat(
                collect($historyLogs)->reject(
                    fn (array $row) => in_array((string) ($row['id'] ?? ''), $liveUuids, true)
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
