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
    $leadName = trim((string) ($log['lead_name'] ?? ''));
    $leadContact = trim((string) ($log['lead_contact'] ?? ''));
    $leadFileName = trim((string) ($log['lead_file_name'] ?? ''));
    $agentName = trim((string) ($log['agent_name'] ?? data_get($log, 'raw.user.name') ?? ''));
    $phoneDisplay = trim((string) ($log['phone_display'] ?? ''));
    if ($phoneDisplay === '') {
        $phoneDisplay = \App\Services\Communications\CommunicationsLeadLookupService::formatPhoneDisplay($callbackPhone)
            ?? ($callbackPhone ?? '—');
    }
    $directionRaw = strtolower((string) ($log['direction'] ?? 'call'));
    $resultLabel = trim((string) ($log['result'] ?? ''));
    $dispositionLabel = trim((string) (
        $log['disposition']
        ?? data_get($log, 'raw.disposition')
        ?? data_get($log, 'raw.meta.disposition')
        ?? data_get($log, 'meta.disposition')
        ?? ''
    ));
    // CDR technical statuses only — never treat agent disposition "No Answer" as a status.
    $resultAsDispositionSkip = ['—', '-', 'completed', 'initiated', 'connected', 'answered', 'no-answer', 'busy', 'failed', 'missed', 'unknown'];
    if ($dispositionLabel !== '' && in_array(strtolower($dispositionLabel), $resultAsDispositionSkip, true)) {
        $dispositionLabel = '';
    }
    if ($dispositionLabel === '' && $resultLabel !== '' && ! in_array(strtolower($resultLabel), $resultAsDispositionSkip, true)) {
        $dispositionLabel = $resultLabel;
    }

    // Collapse repeated disposition/status text saved into notes (e.g. "No AnswerNo Answer…").
    $displayNote = trim(preg_replace("/[ \t]+/", ' ', $displayNote) ?? $displayNote);
    if ($displayNote !== '') {
        $compactNote = preg_replace('/\s+/', '', $displayNote) ?? $displayNote;
        if (preg_match('/^(NoAnswer)+$/i', $compactNote)) {
            $displayNote = 'No Answer';
        } elseif ($dispositionLabel !== '') {
            $compactDispo = preg_replace('/\s+/', '', $dispositionLabel) ?? $dispositionLabel;
            if ($compactDispo !== '' && preg_match('/^('.preg_quote($compactDispo, '/').')+$/i', $compactNote)) {
                $displayNote = $dispositionLabel;
            }
        }
    }

    $statusLikeNotes = ['no answer', 'no-answer', 'busy', 'connected', 'answered', 'failed', 'missed', 'completed', 'initiated'];
    $noteLooksLikeStatus = $displayNote !== '' && in_array(strtolower($displayNote), $statusLikeNotes, true);
    $inCallNotes = trim((string) (
        $log['in_call_notes']
        ?? data_get($log, 'raw.meta.in_call_notes')
        ?? data_get($log, 'meta.in_call_notes')
        ?? ''
    ));
    $showDispositionPreview = $dispositionLabel !== '';
    $showNotePreview = $hasNotes && $displayNote !== '' && ! $noteLooksLikeStatus
        && strcasecmp($displayNote, $dispositionLabel) !== 0;
    $showInCallNotesPreview = $inCallNotes !== ''
        && strcasecmp($inCallNotes, $dispositionLabel) !== 0;
@endphp

<div class="ghl-dialer-recent-row" data-phone-log-row tabindex="0"
    data-log-direction="{{ $log['direction'] ?? 'call' }}"
    data-log-phone="{{ $callbackPhone ?? '' }}"
    data-log-extension="{{ $logExtension ?? '' }}"
    data-log-agent="{{ e($agentName) }}"
    data-log-result="{{ $log['result'] ?? '—' }}"
    data-log-time="{{ $timeLabel }}"
    data-log-call-ref="{{ $callLogRef }}"
    data-log-lead-name="{{ e($leadName) }}"
    data-log-phone-display="{{ e($phoneDisplay) }}"
    data-log-call-note="{{ e($callNote) }}"
    data-log-phone-note="{{ e($phoneNote) }}"
    data-has-notes="{{ $hasNotes ? '1' : '0' }}"
    data-has-recording="{{ $hasRecording ? '1' : '0' }}"
    data-recording-status="{{ $recordingStatus }}"
    data-play-url="{{ $playUrl }}"
    data-download-url="{{ $downloadUrl }}">
    <div class="ghl-dialer-recent-main">
        <div class="ghl-dialer-recent-head">
            <span class="ghl-dialer-recent-dir is-{{ $directionRaw }}">{{ ucfirst($log['direction'] ?? 'call') }}</span>
            @if ($logExtension)
                <span class="ghl-dialer-recent-ext">Ext {{ $logExtension }}</span>
            @endif
            @if ($agentName !== '')
                <span class="ghl-dialer-recent-agent" title="{{ $agentName }}">{{ $agentName }}</span>
            @endif
            @if ($hasRecording)
                <button type="button" class="ghl-dialer-recent-rec" data-recording-play
                    title="Play recording" aria-label="Play call recording">Rec</button>
            @endif
        </div>
        <div class="ghl-dialer-recent-contact">
            @if ($leadName !== '')
                <span class="ghl-dialer-recent-name">{{ $leadName }}</span>
                @if ($leadContact !== '')
                    <span class="ghl-dialer-recent-contact-name">{{ $leadContact }}</span>
                @endif
            @endif
            <span class="ghl-dialer-recent-number">{{ $phoneDisplay }}</span>
            @if ($leadFileName !== '')
                <span class="ghl-dialer-recent-file" title="{{ $leadFileName }}">{{ $leadFileName }}</span>
            @endif
        </div>
        <div class="ghl-dialer-recent-meta-row">
            <span class="ghl-dialer-recent-meta">
                <span class="ghl-dialer-recent-meta__time">{{ $timeAgo }}</span>
                <span class="ghl-dialer-recent-meta__sep" aria-hidden="true">·</span>
                <span class="ghl-dialer-recent-duration">{{ $durationLabel }}</span>
                @if ($resultLabel !== '' && $resultLabel !== '—')
                    <span class="ghl-dialer-recent-meta__sep" aria-hidden="true">·</span>
                    <span class="ghl-dialer-recent-result">{{ $resultLabel }}</span>
                @endif
            </span>
        </div>
        @if ($showDispositionPreview || $showNotePreview || $showInCallNotesPreview)
            <div class="ghl-dialer-recent-fields" data-log-fields>
                @if ($showInCallNotesPreview)
                    <p class="ghl-dialer-recent-field" data-log-notes-line>
                        <span class="ghl-dialer-recent-field__label">Notes:</span>
                        <span class="ghl-dialer-recent-field__value" data-log-notes-preview>{{ $inCallNotes }}</span>
                    </p>
                @endif
                @if ($showNotePreview)
                    <p class="ghl-dialer-recent-field" data-log-comment-line>
                        <span class="ghl-dialer-recent-field__label">Comment:</span>
                        <span class="ghl-dialer-recent-field__value" data-log-note-preview>{{ $displayNote }}</span>
                    </p>
                @endif
                @if ($showDispositionPreview)
                    <p class="ghl-dialer-recent-field ghl-dialer-recent-field--disposition" data-log-disposition-line>
                        <span class="ghl-dialer-recent-field__label">Disposition:</span>
                        <span class="ghl-dialer-recent-field__value" data-log-disposition>{{ $dispositionLabel }}</span>
                    </p>
                @endif
            </div>
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
            <button type="button" class="ghl-dialer-recent-call-btn"
                data-dial-number="{{ $callbackPhone }}">Call</button>
        @endif
    </div>
    @if ($callbackPhone)
        <div class="ghl-dialer-recent-notes hidden" data-log-notes-panel>
            <textarea id="call-log-note-{{ $callLogRef !== '' ? $callLogRef : uniqid('note') }}"
                name="call_log_note"
                class="ghl-dialer-recent-notes-input" data-log-notes-input rows="4"
                placeholder="Notes for this call ({{ $timeLabel }})…" maxlength="5000">{{ $displayNote }}</textarea>
            <div class="ghl-dialer-recent-notes-actions">
                <span class="ghl-dialer-recent-notes-status" data-log-notes-status aria-live="polite"></span>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-log-notes-save>Save</button>
            </div>
        </div>
    @endif
</div>
