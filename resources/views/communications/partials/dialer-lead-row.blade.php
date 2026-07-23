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
    $stateName = trim((string) ($lead['state'] ?? ''));
    $location = trim(implode(', ', array_filter([
        trim((string) ($lead['city'] ?? '')),
        $stateName,
    ])));
    $dispositionRaw = trim((string) ($lead['last_disposition'] ?? ''));
    $pipelineOnly = in_array(strtolower($dispositionRaw), ['new', 'open', 'pending', 'assigned', 'enriched', 'approved', 'ready'], true);
    $disposition = ($dispositionRaw !== '' && ! $pipelineOnly) ? $dispositionRaw : '';
    $lastDialed = trim((string) ($lead['last_dialed_label'] ?? ''));
    if ($lastDialed === '' && ! empty($lead['last_contacted_at'])) {
        try {
            $lastDialed = \Illuminate\Support\Carbon::parse($lead['last_contacted_at'])->diffForHumans();
        } catch (\Throwable) {
            $lastDialed = '';
        }
    }
    $dialMeta = trim(implode(' · ', array_filter([
        $disposition !== '' ? 'Disp: '.$disposition : null,
        $lastDialed !== '' ? 'Last dial: '.$lastDialed : null,
    ])));
    $tags = collect($lead['tags'] ?? [])->filter()->take(4)->values();
    $extras = collect($lead['extra_fields'] ?? [])->filter()->take(3)->values();
    $hasSide = $location !== '' || $tags->isNotEmpty() || $extras->isNotEmpty() || $showFileName;
@endphp

<div class="ghl-dialer-lead-row{{ $hasSide ? ' has-side' : '' }}" data-dialer-lead-row tabindex="0"
    data-lead-id="{{ $lead['id'] ?? '' }}"
    data-lead-phone="{{ $phone }}"
    data-lead-name="{{ e($name) }}"
    data-lead-contact="{{ e($contact) }}"
    data-lead-file-name="{{ e($fileName) }}"
    data-lead-state="{{ e($stateName) }}"
    data-lead-disposition="{{ e($disposition) }}"
    data-lead-last-dialed="{{ e($lastDialed) }}">
    <div class="ghl-dialer-lead-avatar" aria-hidden="true">
        {{ strtoupper(mb_substr($name !== '' ? $name : ($contact !== '' ? $contact : 'L'), 0, 1)) }}
    </div>
    <div class="ghl-dialer-lead-body">
        <div class="ghl-dialer-lead-main">
            @if ($name !== '')
                <span class="ghl-dialer-lead-name">{{ $name }}</span>
            @endif
            @if ($contact !== '')
                <span class="ghl-dialer-lead-contact">{{ $contact }}</span>
            @endif
            <span class="ghl-dialer-lead-number">{{ $phoneDisplay ?: '—' }}</span>
            @if ($dialMeta !== '')
                <span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--dial" title="{{ $dialMeta }}">{{ $dialMeta }}</span>
            @endif
        </div>
        @if ($hasSide)
            <div class="ghl-dialer-lead-side">
                @if ($location !== '')
                    <span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--state" title="{{ $location }}">{{ $location }}</span>
                @endif
                @foreach ($tags as $tag)
                    <span class="ghl-dialer-lead-tag">{{ $tag }}</span>
                @endforeach
                @foreach ($extras as $field)
                    <span class="ghl-dialer-lead-meta" title="{{ $field }}">{{ $field }}</span>
                @endforeach
                @if ($showFileName)
                    <span class="ghl-dialer-lead-meta ghl-dialer-lead-meta--file" title="{{ $fileName }}">{{ $fileName }}</span>
                @endif
            </div>
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
