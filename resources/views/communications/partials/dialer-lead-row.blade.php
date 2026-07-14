@php
    $phone = $lead['phone'] ?? '';
    $name = trim((string) ($lead['name'] ?? ''));
    $contact = trim((string) ($lead['contact'] ?? $lead['owner_name'] ?? ''));
    $phoneDisplay = trim((string) ($lead['phone_display'] ?? ''));
    if ($phoneDisplay === '' && filled($phone)) {
        $phoneDisplay = \App\Services\Communications\CommunicationsLeadLookupService::formatPhoneDisplay($phone) ?? $phone;
    }
    $fileName = trim((string) ($lead['file_name'] ?? $lead['workflow'] ?? ''));
    $showFileName = $fileName !== '' && ! in_array(strtolower($fileName), ['default', 'result', 'n/a', 'none', '-'], true);
@endphp

<div class="ghl-dialer-lead-row" data-dialer-lead-row tabindex="0"
    data-lead-id="{{ $lead['id'] ?? '' }}"
    data-lead-phone="{{ $phone }}"
    data-lead-name="{{ e($name) }}"
    data-lead-contact="{{ e($contact) }}"
    data-lead-file-name="{{ e($fileName) }}">
    <div class="ghl-dialer-lead-avatar" aria-hidden="true">
        {{ strtoupper(mb_substr($name !== '' ? $name : ($contact !== '' ? $contact : 'L'), 0, 1)) }}
    </div>
    <div class="ghl-dialer-lead-main">
        @if ($name !== '')
            <span class="ghl-dialer-lead-name">{{ $name }}</span>
        @endif
        @if ($contact !== '')
            <span class="ghl-dialer-lead-contact">{{ $contact }}</span>
        @endif
        <span class="ghl-dialer-lead-number">{{ $phoneDisplay ?: '—' }}</span>
        @if ($showFileName)
            <span class="ghl-dialer-lead-meta" title="{{ $fileName }}">{{ $fileName }}</span>
        @endif
    </div>
    <div class="ghl-dialer-lead-actions">
        @if ($phone)
            <button type="button" class="ghl-dialer-lead-call-btn" data-dial-lead-call title="Call this lead">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
                <span>Call</span>
            </button>
        @endif
    </div>
</div>
