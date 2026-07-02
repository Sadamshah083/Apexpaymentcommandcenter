@if ($selectedCall ?? null)
    @php
        $log = $selectedCall;
        $callbackPhone = match ($log['direction'] ?? '') {
            'inbound' => $log['from_phone'] ?? null,
            'outbound' => $log['to_phone'] ?? null,
            default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
        };
    @endphp
    <div class="ghl-detail-header">
        <span class="ghl-avatar ghl-avatar-lg">{{ strtoupper(substr($log['direction'] ?? 'C', 0, 1)) }}</span>
        <div class="min-w-0 flex-1">
            <h2 class="text-xl font-bold text-zinc-900">{{ $log['from'] }} → {{ $log['to'] }}</h2>
            <p class="text-sm text-zinc-500 mt-0.5">
                {{ $log['start_time'] ? \Carbon\Carbon::parse($log['start_time'])->format('M j, Y g:i A') : '—' }}
                · {{ $log['result'] }} · {{ $log['duration'] }}s
            </p>
        </div>
        @if ($callbackPhone)
            @include('communications.partials.contact-quick-actions', [
                'routePrefix' => $routePrefix,
                'phone' => $callbackPhone,
            ])
        @endif
    </div>

    <section class="ghl-card">
        <h3 class="ghl-card-title">Call details</h3>
        <dl class="ghl-dl">
            <div>
                <dt>Direction</dt>
                <dd>{{ ucfirst($log['direction'] ?? '—') }}</dd>
            </div>
            <div>
                <dt>From</dt>
                <dd>{{ $log['from_phone'] ?? ($log['from'] ?? '—') }}</dd>
            </div>
            <div>
                <dt>To</dt>
                <dd>{{ $log['to_phone'] ?? ($log['to'] ?? '—') }}</dd>
            </div>
            <div>
                <dt>Result</dt>
                <dd>{{ $log['result'] ?? '—' }}</dd>
            </div>
        </dl>
        @if (!empty($log['has_recording_media']) && !empty($log['recording_id']))
            <div class="mt-3">
                @include('communications.partials.recording-actions', [
                    'routePrefix' => $routePrefix,
                    'recordingId' => $log['recording_id'],
                    'callReferenceId' => $log['call_reference_id'] ?? $log['recording_id'],
                    'source' => $log['recording_source'] ?? 'phone',
                    'hasMedia' => true,
                ])
            </div>
        @endif
    </section>

    @if (($log['result'] ?? '') === 'Active Call')
        @include('communications.partials.morpheus-call-controls', [
            'routePrefix' => $routePrefix,
            'uuid' => $log['id'],
            'log' => $log,
            'morpheusQueues' => $morpheusQueues ?? [],
            'morpheusUsers' => $morpheusUsers ?? [],
            'morpheusConferences' => $morpheusConferences ?? [],
        ])
    @endif
@else
    @include('communications.inbox.partials.empty', [
        'title' => 'Call not found',
        'message' => 'Select a call from the list.',
    ])
@endif
