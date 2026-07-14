@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $callbackPhone = match ($log['direction'] ?? '') {
        'inbound' => $log['from_phone'] ?? null,
        'outbound' => $log['to_phone'] ?? null,
        default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
    };
    $hasRecording = !empty($log['has_recording_media']) && !empty($log['recording_id']);
    $recordingStatus = (string) ($log['recording_status'] ?? ($hasRecording ? 'ready' : 'none'));
    $recordingSource = $log['recording_source'] ?? 'morpheus';
    $callRef = $log['call_reference_id'] ?? $log['id'] ?? '';
    $callLogRef = (string) ($log['id'] ?? '');
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
    $leadName = trim((string) ($log['lead_name'] ?? ''));
    $phoneDisplay = trim((string) ($log['phone_display'] ?? ''));
    if ($phoneDisplay === '') {
        $phoneDisplay = \App\Services\Communications\CommunicationsLeadLookupService::formatPhoneDisplay($callbackPhone)
            ?? ($callbackPhone ?? '—');
    }
    $disposition = trim((string) ($log['disposition'] ?? $log['result'] ?? ''));
    $agentName = trim((string) ($log['agent_name'] ?? data_get($log, 'raw.user.name') ?? ''));
    $agentRoleLabel = trim((string) ($log['agent_role_label'] ?? ''));
@endphp

<div class="ghl-dialer-recording-row" data-phone-log-row data-recording-row tabindex="0"
    data-log-direction="{{ $log['direction'] ?? 'call' }}"
    data-log-phone="{{ $callbackPhone ?? '' }}"
    data-log-result="{{ $log['result'] ?? '—' }}"
    data-log-time="{{ $timeLabel }}"
    data-log-call-ref="{{ $callLogRef }}"
    data-log-lead-name="{{ e($leadName) }}"
    data-log-phone-display="{{ e($phoneDisplay) }}"
    data-has-recording="{{ $hasRecording ? '1' : '0' }}"
    data-recording-status="{{ $recordingStatus }}"
    data-play-url="{{ $playUrl }}"
    data-download-url="{{ $downloadUrl }}">
    <div class="ghl-dialer-recording-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
            <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
            <line x1="12" y1="19" x2="12" y2="23"/>
            <line x1="8" y1="23" x2="16" y2="23"/>
        </svg>
    </div>
    <div class="ghl-dialer-recording-main">
        @if ($leadName !== '')
            <span class="ghl-dialer-recording-name">{{ $leadName }}</span>
        @endif
        <span class="ghl-dialer-recording-number">{{ $phoneDisplay }}</span>
        <span class="ghl-dialer-recording-meta">
            {{ ucfirst($log['direction'] ?? 'call') }} · {{ $timeAgo }} · {{ $durationLabel }}
            @if ($agentName !== '')
                · {{ $agentName }}
            @endif
            @if ($agentRoleLabel !== '')
                · <span class="ghl-dialer-recording-role">{{ $agentRoleLabel }}</span>
            @endif
            @if ($disposition !== '' && $disposition !== '—')
                · {{ $disposition }}
            @endif
        </span>
    </div>
    <div class="ghl-dialer-recording-actions">
        @if ($hasRecording)
            <button type="button" class="ghl-dialer-recording-play" data-recording-play title="Play recording">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Play
            </button>
            @if ($downloadUrl)
                <a class="ghl-dialer-recording-download" href="{{ $downloadUrl }}" download title="Download recording">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                        <polyline points="7 10 12 15 17 10" />
                        <line x1="12" y1="15" x2="12" y2="3" />
                    </svg>
                    <span>Download</span>
                </a>
            @endif
        @else
            <span class="ghl-dialer-recording-pending">{{ $recordingStatus === 'pending' ? 'Saving…' : 'Unavailable' }}</span>
        @endif
    </div>
</div>
