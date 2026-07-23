<?php

namespace App\Services\Communications;

use App\Models\CommunicationCallLog;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Log;

class CommunicationsCallRecordingService
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public function __construct(
        protected ZoomApiService $zoom,
    ) {}

    /**
     * Poll Morpheus for a finalized recording and persist it on the local call log.
     */
    public function resolveAndPersist(CommunicationCallLog $log, int $attempt = 1): CommunicationCallLog
    {
        $log->refresh();

        if ($log->recording_status === self::STATUS_READY && filled($log->recording_file_id)) {
            return $log;
        }

        $uuid = trim((string) $log->morpheus_call_uuid);
        if ($uuid === '') {
            return $log;
        }

        $snap = $this->zoom->getCall($uuid);
        $cdrHasRecording = (bool) data_get($snap, 'has_recording')
            || (bool) data_get($snap, 'raw.has_recording');
        $billsec = (int) (data_get($snap, 'billsec') ?? data_get($snap, 'duration') ?? $log->duration_sec ?? 0);
        // Morpheus CDR often omits has_recording even when a file exists — still look up by UUID.
        $shouldLookup = $cdrHasRecording || $billsec > 0 || (int) ($log->duration_sec ?? 0) > 0;

        if (! $shouldLookup) {
            $log->update([
                'recording_status' => self::STATUS_UNAVAILABLE,
                'meta' => array_merge($log->meta ?? [], [
                    'recording_skip_reason' => 'no_billable_seconds',
                    'recording_attempt' => max($attempt, (int) data_get($log->meta, 'recording_attempt', 0)),
                ]),
            ]);

            return $log->fresh();
        }

        $fileId = $this->zoom->findRecordingFileIdForCall($uuid, $snap ?: null, $log);

        if ($fileId) {
            $log->update([
                'recording_file_id' => $fileId,
                'recording_source' => 'morpheus',
                'recording_status' => self::STATUS_READY,
                'meta' => array_merge($log->meta ?? [], [
                    'recording_resolved_at' => now()->toIso8601String(),
                    'recording_attempt' => $attempt,
                ]),
            ]);

            CommunicationsInboxService::bumpDialerLogsCacheVersion($log->user_id);
            if ($log->workspace_id) {
                AgentStatusReportService::forgetCachesForWorkspace((int) $log->workspace_id);
            }

            return $log->fresh();
        }

        // No-answer / ring-only legs usually never produce a recording.
        if ($billsec <= 0 && ! $cdrHasRecording) {
            $log->update([
                'recording_status' => self::STATUS_UNAVAILABLE,
                'meta' => array_merge($log->meta ?? [], [
                    'recording_last_attempt_at' => now()->toIso8601String(),
                    'recording_attempt' => max($attempt, (int) data_get($log->meta, 'recording_attempt', 0) + 1),
                    'cdr_has_recording' => false,
                    'recording_skip_reason' => 'zero_billsec',
                ]),
            ]);

            return $log->fresh();
        }

        // Keep Find clickable — recordings often finalize a few minutes after hangup.
        $priorAttempts = (int) data_get($log->meta, 'recording_attempt', 0);
        $nextAttempt = max($attempt, $priorAttempts + 1);
        $status = $nextAttempt >= 8 ? self::STATUS_UNAVAILABLE : self::STATUS_PENDING;
        $log->update([
            'recording_status' => $status,
            'meta' => array_merge($log->meta ?? [], [
                'recording_last_attempt_at' => now()->toIso8601String(),
                'recording_attempt' => $nextAttempt,
                'cdr_has_recording' => $cdrHasRecording,
            ]),
        ]);

        if ($status === self::STATUS_UNAVAILABLE) {
            Log::info('communications.recording.unavailable', [
                'call_log_id' => $log->id,
                'morpheus_call_uuid' => $uuid,
                'attempt' => $nextAttempt,
            ]);
        }

        return $log->fresh();
    }

    public function persistFromHubLog(CommunicationCallLog $log, array $hubLog): CommunicationCallLog
    {
        $fileId = (string) ($hubLog['recording_id'] ?? '');
        $hasMedia = ! empty($hubLog['has_recording_media']) && $fileId !== '';

        if (! $hasMedia) {
            return $log;
        }

        if ($log->recording_status === self::STATUS_READY && filled($log->recording_file_id)) {
            return $log;
        }

        $log->update([
            'recording_file_id' => $fileId,
            'recording_source' => (string) ($hubLog['recording_source'] ?? 'morpheus'),
            'recording_status' => self::STATUS_READY,
            'meta' => array_merge($log->meta ?? [], [
                'recording_imported_from_hub' => now()->toIso8601String(),
            ]),
        ]);

        return $log->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function recordingFieldsForHubLog(CommunicationCallLog $row): array
    {
        if ($row->recording_status === self::STATUS_READY && filled($row->recording_file_id)) {
            return [
                'recording' => 'Yes',
                'has_recording_media' => true,
                'recording_id' => (string) $row->recording_file_id,
                'recording_source' => (string) ($row->recording_source ?: 'morpheus'),
                'call_reference_id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
                'recording_status' => self::STATUS_READY,
            ];
        }

        if ($row->recording_status === self::STATUS_PENDING) {
            return [
                'recording' => 'Pending',
                'has_recording_media' => false,
                'recording_id' => null,
                'recording_source' => null,
                'call_reference_id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
                'recording_status' => self::STATUS_PENDING,
            ];
        }

        return [
            'recording' => '—',
            'has_recording_media' => false,
            'recording_id' => null,
            'recording_source' => null,
            'call_reference_id' => $row->morpheus_call_uuid ?: ('local:'.$row->id),
            'recording_status' => (string) ($row->recording_status ?: self::STATUS_NONE),
        ];
    }
}
