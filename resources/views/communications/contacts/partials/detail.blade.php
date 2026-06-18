<div class="ghl-detail-header">
    <span class="ghl-avatar ghl-avatar-lg">{{ strtoupper(substr($contact['name'], 0, 2)) }}</span>
    <div class="min-w-0 flex-1">
        <h2 class="text-xl font-bold text-zinc-900 truncate">{{ $contact['name'] }}</h2>
        <p class="text-sm text-zinc-500 mt-0.5">{{ $contact['tag'] ?? 'contact' }}</p>
    </div>
    @include('communications.partials.contact-quick-actions', [
        'routePrefix' => $routePrefix,
        'contact' => $contact,
        'smsSession' => $smsSession ?? null,
    ])
</div>

<div class="ghl-detail-grid">
    <section class="ghl-card">
        <h3 class="ghl-card-title">Contact info</h3>
        <dl class="ghl-dl">
            <div><dt>Phone</dt><dd>{{ $contact['phone'] ?? '—' }}</dd></div>
            <div><dt>Email</dt><dd>{{ $contact['email'] ?? '—' }}</dd></div>
            <div><dt>Last activity</dt>
                <dd>
                    @if($stats['last_activity_at'] ?? $contact['last_activity_at'] ?? null)
                        {{ \Carbon\Carbon::parse($stats['last_activity_at'] ?? $contact['last_activity_at'])->format('M j, Y g:i A') }}
                    @else
                        —
                    @endif
                </dd>
            </div>
        </dl>
        <div class="ghl-stat-row">
            <div class="ghl-stat-chip">
                <span class="ghl-stat-chip-value">{{ $stats['activity_count'] ?? $stats['call_count'] ?? 0 }}</span>
                <span class="ghl-stat-chip-label">Activities</span>
            </div>
            <div class="ghl-stat-chip">
                <span class="ghl-stat-chip-value">{{ $stats['call_count'] ?? 0 }}</span>
                <span class="ghl-stat-chip-label">Calls</span>
            </div>
            <div class="ghl-stat-chip">
                <span class="ghl-stat-chip-value">{{ $stats['sms_count'] ?? 0 }}</span>
                <span class="ghl-stat-chip-label">SMS</span>
            </div>
            <div class="ghl-stat-chip">
                <span class="ghl-stat-chip-value">{{ $stats['voicemail_count'] ?? 0 }}</span>
                <span class="ghl-stat-chip-label">Voicemails</span>
            </div>
        </div>
    </section>

    <section class="ghl-card ghl-conversation">
        <h3 class="ghl-card-title">Activity timeline</h3>
        <div class="ghl-thread">
            @forelse($timeline as $event)
                @php
                    $eventType = $event['type'] ?? 'call';
                    $isInbound = ($event['direction'] ?? '') === 'inbound';
                @endphp
                <article class="ghl-message {{ $isInbound ? 'ghl-message-in' : 'ghl-message-out' }}">
                    <div class="ghl-message-bubble">
                        <div class="ghl-message-title">
                            <span class="ghl-activity-type ghl-activity-type-{{ $eventType }}">{{ strtoupper($eventType) }}</span>
                            {{ $event['label'] }}
                        </div>
                        @if($eventType === 'call')
                            <div class="ghl-message-body">{{ $event['from'] }} → {{ $event['to'] }}</div>
                        @elseif($eventType === 'sms')
                            <div class="ghl-message-body">{{ $event['detail'] }}</div>
                        @elseif($eventType === 'voicemail')
                            <div class="ghl-message-body">{{ $event['from'] }} → {{ $event['to'] }}</div>
                            @if(!empty($event['transcription']))
                                <div class="ghl-message-body text-zinc-600 mt-1">{{ $event['transcription'] }}</div>
                            @endif
                        @endif
                        <div class="ghl-message-meta">{{ $event['detail'] }} · {{ \Carbon\Carbon::parse($event['at'])->format('M j, g:i A') }}</div>
                        <div class="ghl-message-actions">
                            @if($eventType === 'call' && !empty($event['has_recording_media']) && !empty($event['recording_id']))
                                @include('communications.partials.recording-actions', [
                                    'routePrefix' => $routePrefix,
                                    'recordingId' => $event['recording_id'],
                                    'callReferenceId' => $event['call_reference_id'] ?? $event['recording_id'],
                                    'source' => $event['recording_source'] ?? 'phone',
                                    'hasMedia' => true,
                                ])
                            @elseif($eventType === 'sms' && !empty($event['session_id']))
                                <a href="{{ route($routePrefix.'communications.index', ['mode' => 'sms', 'session' => $event['session_id']]) }}"
                                   class="comm-hub-link">View thread</a>
                            @elseif($eventType === 'voicemail' && !empty($event['file_id']) && !empty($event['has_media']))
                                @include('communications.partials.voicemail-actions', [
                                    'routePrefix' => $routePrefix,
                                    'fileId' => $event['file_id'],
                                    'hasMedia' => true,
                                ])
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <p class="ghl-empty py-8">No activity with this contact in the selected period.</p>
            @endforelse
        </div>
    </section>
</div>
