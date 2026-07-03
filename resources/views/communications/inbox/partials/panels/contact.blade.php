<div class="ghl-conv-header">
    <span class="ghl-avatar ghl-avatar-lg">{{ strtoupper(substr($contact['name'], 0, 2)) }}</span>
    <div class="ghl-conv-header-info">
        <h2 class="ghl-conv-header-name" title="{{ $contact['name'] }}">{{ $contact['name'] }}</h2>
        <p class="ghl-conv-header-sub"
            title="{{ ($contact['phone'] ?? ($contact['email'] ?? 'No phone or email')) . ' · ' . ($contact['tag'] ?? 'contact') }}">{{ $contact['phone'] ?? ($contact['email'] ?? 'No phone or email') }} ·
            {{ $contact['tag'] ?? 'contact' }}</p>
    </div>
    @include('communications.partials.contact-quick-actions', [
        'routePrefix' => $routePrefix,
        'contact' => $contact,
        'smsSession' => $smsSession ?? null,
    ])
</div>

<div class="ghl-contact-strip">
    <div class="ghl-contact-strip-item"><span
            class="ghl-contact-strip-value">{{ $contactStats['activity_count'] ?? 0 }}</span><span
            class="ghl-contact-strip-label">Activities</span></div>
    <div class="ghl-contact-strip-item"><span
            class="ghl-contact-strip-value">{{ $contactStats['call_count'] ?? 0 }}</span><span
            class="ghl-contact-strip-label">Calls</span></div>
    <div class="ghl-contact-strip-item"><span
            class="ghl-contact-strip-value">{{ $contactStats['sms_count'] ?? 0 }}</span><span
            class="ghl-contact-strip-label">SMS</span></div>
    <div class="ghl-contact-strip-item"><span
            class="ghl-contact-strip-value">{{ $contactStats['voicemail_count'] ?? 0 }}</span><span
            class="ghl-contact-strip-label">Voicemail</span></div>
</div>

<div class="ghl-contact-layout" style="padding: 1rem 1.25rem;">
    <section class="ghl-card">
        <h3 class="ghl-card-title">Details</h3>
        <dl class="ghl-dl">
            <div>
                <dt>Phone</dt>
                <dd>{{ $contact['phone'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>Email</dt>
                <dd>{{ $contact['email'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>Last activity</dt>
                <dd>
                    @if ($contactStats['last_activity_at'] ?? ($contact['last_activity_at'] ?? null))
                        {{ \Carbon\Carbon::parse($contactStats['last_activity_at'] ?? $contact['last_activity_at'])->format('M j, Y g:i A') }}
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <section class="ghl-card ghl-conversation" style="min-height: 320px;">
        <h3 class="ghl-card-title">Activity timeline</h3>
        <div class="ghl-timeline">
            @forelse($timeline as $event)
                @php
                    $eventType = $event['type'] ?? 'call';
                    $isOut = ($event['direction'] ?? '') === 'outbound';
                @endphp
                <article class="ghl-timeline-item {{ $isOut ? 'ghl-timeline-item-out' : '' }}">
                    <span
                        class="ghl-timeline-icon ghl-timeline-icon-{{ $eventType }}">{{ strtoupper(substr($eventType, 0, 1)) }}</span>
                    <div class="ghl-timeline-bubble">
                        <div class="ghl-timeline-title">{{ $event['label'] }}</div>
                        @if ($eventType === 'call')
                            <div class="ghl-timeline-body">{{ $event['from'] }} → {{ $event['to'] }}</div>
                        @elseif($eventType === 'sms')
                            <div class="ghl-timeline-body">{{ $event['detail'] }}</div>
                        @elseif($eventType === 'voicemail')
                            <div class="ghl-timeline-body">{{ $event['from'] }} → {{ $event['to'] }}</div>
                            @if (!empty($event['transcription']))
                                <div class="ghl-timeline-body" style="font-style: italic;">
                                    {{ $event['transcription'] }}</div>
                            @endif
                        @endif
                        <div class="ghl-timeline-meta">{{ $event['detail'] }} ·
                            {{ \Carbon\Carbon::parse($event['at'])->format('M j, g:i A') }}</div>
                        <div class="ghl-timeline-actions">
                            @if ($eventType === 'call' && !empty($event['has_recording_media']))
                                @include('communications.partials.recording-actions', [
                                    'routePrefix' => $routePrefix,
                                    'recordingId' => $event['recording_id'],
                                    'callReferenceId' => $event['call_reference_id'] ?? $event['recording_id'],
                                    'source' => $event['recording_source'] ?? 'phone',
                                    'hasMedia' => true,
                                ])
                            @elseif($eventType === 'sms' && !empty($event['session_id']))
                                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'sms', 'session' => $event['session_id']]) }}"
                                    class="comm-hub-link">Open SMS</a>
                            @elseif($eventType === 'voicemail' && !empty($event['file_id']))
                                @include('communications.partials.voicemail-actions', [
                                    'routePrefix' => $routePrefix,
                                    'fileId' => $event['file_id'],
                                    'hasMedia' => $event['has_media'] ?? true,
                                ])
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <p class="ghl-empty py-6">No activity in the selected date range.</p>
            @endforelse
        </div>
        <x-communications.list-pagination :pagination="$panelPagination ?? null" class="mt-4" />
    </section>
</div>
