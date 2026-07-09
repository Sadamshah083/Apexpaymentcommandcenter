@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $callbackPhone = match ($log['direction'] ?? '') {
        'inbound' => $log['from_phone'] ?? null,
        'outbound' => $log['to_phone'] ?? null,
        default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
    };
    $logExtension = $log['from_extension']
        ?? data_get($log, 'raw.from_extension')
        ?? null;
    if (!$logExtension && !empty($log['from']) && preg_match('/ext\s+(\d+)/i', (string) $log['from'], $m)) {
        $logExtension = $m[1];
    }
    $hasRecording = !empty($log['has_recording_media']) && !empty($log['recording_id']);
    $recordingStatus = (string) ($log['recording_status'] ?? ($hasRecording ? 'ready' : 'none'));
    $recordingSource = $log['recording_source'] ?? 'morpheus';
    $callRef = $log['call_reference_id'] ?? $log['id'] ?? '';
    $callLogRef = (string) ($log['id'] ?? '');
    $callNote = trim((string) ($log['call_note'] ?? $log['note'] ?? data_get($log, 'raw.note') ?? ''));
    $phoneNote = trim((string) ($log['phone_note'] ?? ''));
    $hasNotes = $callNote !== '';
    $playUrl = $hasRecording
        ? route($routePrefix . 'communications.zoom.recordings.media', [
            'recordingId' => $log['recording_id'],
            'source' => $recordingSource,
            'action' => 'play',
            'call_ref' => $callRef,
        ])
        : '';
    $downloadUrl = $hasRecording
        ? route($routePrefix . 'communications.zoom.recordings.media', [
            'recordingId' => $log['recording_id'],
            'source' => $recordingSource,
            'action' => 'download',
            'call_ref' => $callRef,
        ])
        : '';
    $timeLabel = !empty($log['start_time'])
        ? \Carbon\Carbon::parse($log['start_time'])->format('M j, Y g:i A')
        : '—';
    $timeAgo = !empty($log['start_time'])
        ? \Carbon\Carbon::parse($log['start_time'])->diffForHumans(short: true)
        : '—';
    $durationSeconds = (int) (
        $log['duration']
        ?? data_get($log, 'raw.billsec')
        ?? data_get($log, 'raw.duration_sec')
        ?? data_get($log, 'raw.duration')
        ?? 0
    );
    $durationLabel = \App\Services\Communications\CommunicationsInboxService::formatDialerCallDuration($durationSeconds);
    $displayNote = $callNote;
@endphp

<div class="ghl-dialer-recent-row" data-phone-log-row tabindex="0"
    data-log-direction="{{ $log['direction'] ?? 'call' }}"
    data-log-phone="{{ $callbackPhone ?? '' }}"
    data-log-extension="{{ $logExtension ?? '' }}"
    data-log-result="{{ $log['result'] ?? '—' }}"
    data-log-time="{{ $timeLabel }}"
    data-log-call-ref="{{ $callLogRef }}"
    data-log-call-note="{{ e($callNote) }}"
    data-log-phone-note="{{ e($phoneNote) }}"
    data-has-notes="{{ $hasNotes ? '1' : '0' }}"
    data-has-recording="{{ $hasRecording ? '1' : '0' }}"
    data-recording-status="{{ $recordingStatus }}"
    data-play-url="{{ $playUrl }}"
    data-download-url="{{ $downloadUrl }}">
    <div class="ghl-dialer-recent-main">
        <div class="ghl-dialer-recent-head">
            <span class="ghl-dialer-recent-dir">{{ ucfirst($log['direction'] ?? 'call') }}</span>
            @if ($logExtension)
                <span class="ghl-dialer-recent-ext">Ext {{ $logExtension }}</span>
            @endif
        </div>
        <span class="ghl-dialer-recent-number">{{ $callbackPhone ?? '—' }}</span>
        <span class="ghl-dialer-recent-meta">
            {{ $timeAgo }} · {{ $durationLabel }} · {{ $log['result'] ?? '—' }}
        </span>
        @if ($hasNotes && $displayNote !== '')
            <span class="ghl-dialer-recent-note-preview" data-log-note-preview>{{ $displayNote }}</span>
        @endif
    </div>
    <div class="ghl-dialer-recent-actions">
        @if ($callbackPhone)
            <button type="button" class="ghl-dialer-recent-notes-btn {{ $hasNotes ? 'has-notes' : '' }}"
                data-log-notes-toggle aria-expanded="false"
                title="Notes" aria-label="Toggle notes for call at {{ $timeLabel }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <polyline points="14 2 14 8 20 8" />
                    <line x1="16" y1="13" x2="8" y2="13" />
                    <line x1="16" y1="17" x2="8" y2="17" />
                </svg>
            </button>
            <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm"
                data-dial-number="{{ $callbackPhone }}">Call</button>
        @endif
    </div>
    @if ($callbackPhone)
        <div class="ghl-dialer-recent-notes hidden" data-log-notes-panel>
            <textarea class="ghl-dialer-recent-notes-input" data-log-notes-input rows="4"
                placeholder="Notes for this call ({{ $timeLabel }})…" maxlength="5000">{{ $displayNote }}</textarea>
            <div class="ghl-dialer-recent-notes-actions">
                <span class="ghl-dialer-recent-notes-status" data-log-notes-status aria-live="polite"></span>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-log-notes-save>Save</button>
            </div>
        </div>
    @endif
</div>
