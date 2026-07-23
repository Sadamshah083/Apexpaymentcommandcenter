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
        } elseif (array_key_exists('user_ids', $filters)) {
            $userIds = array_values(array_filter(array_map('intval', (array) $filters['user_ids'])));
            if ($userIds === []) {
                return [];
            }
            $query->whereIn('user_id', $userIds);
        } elseif (! empty($filters['from_extension'])) {
            $ext = preg_replace('/\D/', '', (string) $filters['from_extension']) ?: (string) $filters['from_extension'];
            if ($ext !== '') {
                $query->where('from_extension', $ext);
            }
        }

        $limit = isset($filters['limit'])
            ? max(1, min(250, (int) $filters['limit']))
            : ((! empty($filters['user_id']) || ! empty($filters['user_ids']) || ! empty($filters['recordings_only']))
                ? 250
                : 100);
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        return $query
            ->orderByDesc('created_at')
            ->offset($offset)
            ->limit($limit)
            ->get()
            ->map(fn (CommunicationCallLog $row) => $this->toHubLog($row))
            ->all();
    }

    /**
     * Fast dialer page: local DB only (limit+1 to detect has_more).
     *
     * @return array{logs: array<int, array<string, mixed>>, has_more: bool}
     */
    public function pageForHub(Workspace $workspace, array $filters, int $offset, int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $rows = $this->listForHub($workspace, array_merge($filters, [
            'offset' => $offset,
            'limit' => $limit + 1,
        ]));
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        return [
            'logs' => $rows,
            'has_more' => $hasMore,
        ];
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
        $destinationConnected = (bool) ($snap['destination_connected'] ?? $snap['destination_answered'] ?? false);

        $status = match (true) {
            in_array($cause, ['USER_BUSY', 'CALL_REJECTED'], true) => 'busy',
            $billsec > 0 || $destinationConnected => 'completed',
            in_array($cause, ['NO_USER_RESPONSE', 'NO_ANSWER', 'ORIGINATOR_CANCEL'], true) => 'no_answer',
            default => 'completed',
        };

        $callResult = match (true) {
            $billsec > 0 || $destinationConnected => 'connected',
            $status === 'busy' => 'busy',
            $status === 'no_answer' => 'no-answer',
            default => 'initiated',
        };

        $log->update([
            'status' => $status,
            // Persist 0s connected/initiated calls instead of nulling duration.
            'duration_sec' => max(0, $billsec),
            'ended_at' => now(),
            'meta' => array_merge($log->meta ?? [], [
                'cdr_destination' => $dest,
                'hangup_cause' => $cause,
                'cdr_synced_at' => now()->toIso8601String(),
                'cdr_has_recording' => (bool) ($snap['has_recording'] ?? data_get($snap, 'raw.has_recording')),
                'call_result' => $callResult,
                'destination_connected' => $destinationConnected,
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
        // Column is varchar(120) — never let a long free-text value blow up the save.
        if (mb_strlen($disposition) > 120) {
            $disposition = mb_substr($disposition, 0, 120);
        }
        // Keep comment separate from disposition so Call logs can show both cleanly.
        $historyNote = filled($payload['note'] ?? null) ? trim((string) $payload['note']) : null;
        $uuid = trim((string) ($payload['call_uuid'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $durationSec = array_key_exists('duration_sec', $payload) && $payload['duration_sec'] !== null
            ? max(0, (int) $payload['duration_sec'])
            : null;
        /** @var User|null $user */
        $user = $payload['user'] ?? null;
        $leadId = isset($payload['lead_id']) ? (int) $payload['lead_id'] : null;
        $dialMode = trim((string) ($payload['dial_mode'] ?? ''));
        $connectedFlag = (bool) ($payload['connected'] ?? false);
        $requestedResult = strtolower(trim((string) ($payload['call_result'] ?? '')));

        $log = null;
        if ($uuid !== '') {
            $log = CommunicationCallLog::query()
                ->where('workspace_id', $workspace->id)
                ->where('morpheus_call_uuid', $uuid)
                ->latest('id')
                ->first();
        }

        // Only attach to an open (empty disposition) outbound row for this phone.
        // Never overwrite a prior disposition on the same number.
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

        // Connected/initiated 0s calls still count in call data (not forced to no-answer).
        $callResult = match (true) {
            in_array($requestedResult, ['connected', 'answered', 'initiated'], true) => (
                $requestedResult === 'answered' ? 'connected' : $requestedResult
            ),
            $connectedFlag => 'connected',
            ($durationSec ?? 0) > 0 => 'connected',
            default => 'no-answer',
        };
        $inCallNotes = filled($payload['in_call_notes'] ?? null) ? trim((string) $payload['in_call_notes']) : null;
        $meta = [
            'source' => 'dialer_disposition',
            'lead_id' => $leadId ?: null,
            'call_result' => $callResult,
            'dial_mode' => $dialMode !== '' ? $dialMode : null,
        ];
        if ($disposition !== '') {
            $meta['disposition'] = $disposition;
        }
        if ($inCallNotes !== null && $inCallNotes !== '') {
            $meta['in_call_notes'] = $inCallNotes;
        }

        $existingDisposition = trim((string) ($log?->disposition ?? ''));

        // Same UUID already dispositioned → append a new call-log row (multi-attempt history).
        if ($log && $existingDisposition !== '') {
            $log = CommunicationCallLog::create([
                'workspace_id' => $workspace->id,
                'user_id' => $user?->id,
                'morpheus_call_uuid' => $uuid !== '' ? $uuid : null,
                'direction' => 'outbound',
                'to_phone' => $phone !== '' ? $phone : ($log->to_phone ?: null),
                'disposition' => $disposition !== '' ? $disposition : null,
                'note' => $historyNote !== '' && $historyNote !== null ? $historyNote : null,
                'status' => 'completed',
                'duration_sec' => $durationSec,
                'started_at' => $durationSec ? now()->subSeconds($durationSec) : now(),
                'ended_at' => now(),
                'meta' => array_merge($meta, ['appended_after_prior_disposition' => true]),
            ]);
        } elseif (! $log) {
            $log = CommunicationCallLog::create([
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
        } else {
            $mergedMeta = array_merge($log->meta ?? [], $meta);
            $log->update([
                'disposition' => $disposition !== '' ? $disposition : $log->disposition,
                'note' => $historyNote !== null && $historyNote !== '' ? $historyNote : $log->note,
                'status' => 'completed',
                'ended_at' => now(),
                'duration_sec' => $durationSec ?? $log->duration_sec ?? ($log->started_at ? (int) $log->started_at->diffInSeconds(now()) : null),
                'to_phone' => $log->to_phone ?: ($phone !== '' ? $phone : null),
                'morpheus_call_uuid' => $log->morpheus_call_uuid ?: ($uuid !== '' ? $uuid : null),
                'user_id' => $log->user_id ?: $user?->id,
                'meta' => $mergedMeta,
            ]);
            $log->disposition = $disposition !== '' ? $disposition : $log->disposition;
            $log->meta = $mergedMeta;
            if ($durationSec !== null) {
                $log->duration_sec = $durationSec;
            }
        }

        // Append-only disposition history — same number/lead can store unlimited attempts.
        $this->appendLeadDisposition($workspace, [
            'user_id' => $user?->id,
            'workflow_lead_id' => $leadId ?: null,
            'communication_call_log_id' => $log->id,
            'phone' => $phone !== '' ? $phone : ($log->to_phone ?: null),
            'call_uuid' => $uuid !== '' ? $uuid : ($log->morpheus_call_uuid ?: null),
            'disposition' => $disposition,
            'note' => $historyNote,
            'duration_sec' => $durationSec ?? $log->duration_sec,
            'dial_mode' => $dialMode !== '' ? $dialMode : null,
            'meta' => $meta,
        ]);

        return $log;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function appendLeadDisposition(Workspace $workspace, array $payload): void
    {
        $disposition = trim((string) ($payload['disposition'] ?? ''));
        if ($disposition === '') {
            return;
        }

        try {
            \App\Models\LeadDisposition::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $payload['user_id'] ?? null,
                'workflow_lead_id' => $payload['workflow_lead_id'] ?? null,
                'communication_call_log_id' => $payload['communication_call_log_id'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'call_uuid' => $payload['call_uuid'] ?? null,
                'disposition' => mb_substr($disposition, 0, 120),
                'note' => $payload['note'] ?? null,
                'duration_sec' => isset($payload['duration_sec']) ? max(0, (int) $payload['duration_sec']) : null,
                'dial_mode' => $payload['dial_mode'] ?? null,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : null,
            ]);
        } catch (\Throwable) {
            // History table may not be migrated yet — call log still saved.
        }
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
            $destinationConnected = (bool) data_get($row->meta, 'destination_connected', false);
            $callResult = match (true) {
                ($row->duration_sec ?? 0) > 0 || $destinationConnected => 'connected',
                in_array(strtolower((string) ($row->status ?: '')), ['connected', 'completed', 'initiated'], true) => (
                    strtolower((string) $row->status) === 'initiated' ? 'initiated' : 'connected'
                ),
                default => ucfirst((string) ($row->status ?: 'completed')),
            };
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

    /**
     * Total outbound dials placed by this agent in the workspace.
     */
    public function countDialsForUser(Workspace $workspace, User $user): int
    {
        return (int) CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('direction', 'outbound')
            ->count();
    }

    /**
     * Most recent dial attempt for a phone number (outbound to / inbound from).
     *
     * @return array<string, mixed>|null
     */
    public function recentDialForPhone(Workspace $workspace, string $phone, ?int $userId = null): ?array
    {
        $key = app(CommunicationsPhoneNotesService::class)->normalizePhoneKey($phone);
        if (! $key) {
            return null;
        }

        $suffix = strlen($key) >= 10 ? substr($key, -10) : $key;
        if ($suffix === '') {
            return null;
        }

        $query = CommunicationCallLog::query()
            ->where('workspace_id', $workspace->id)
            ->with('user:id,name')
            ->where(function ($phoneMatch) use ($suffix) {
                $phoneMatch
                    ->where('to_phone', 'like', '%'.$suffix)
                    ->orWhere('from_phone', 'like', '%'.$suffix);
            })
            ->orderByDesc('created_at');

        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $hub = $this->toHubLog($row);
        $started = $row->started_at ?? $row->created_at;

        return [
            'id' => $hub['id'] ?? null,
            'direction' => $hub['direction'] ?? null,
            'phone' => $hub['to_phone'] ?: ($hub['from_phone'] ?? ''),
            'disposition' => $hub['disposition'] ?? null,
            'result' => $hub['result'] ?? null,
            'note' => trim((string) ($hub['note'] ?? '')),
            'duration' => (int) ($hub['duration'] ?? 0),
            'duration_label' => CommunicationsInboxService::formatDialerCallDuration((int) ($hub['duration'] ?? 0)),
            'agent_name' => $hub['agent_name'] ?? null,
            'start_time' => $hub['start_time'] ?? null,
            'time_ago' => $started ? $started->diffForHumans(short: true) : null,
            'time_label' => $started ? $started->format('M j, Y g:i A') : null,
        ];
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
