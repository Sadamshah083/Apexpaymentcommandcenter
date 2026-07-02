@if ($selectedVoicemail ?? null)
    @php $vm = $selectedVoicemail; @endphp
    <div class="ghl-detail-header">
        <span class="ghl-avatar ghl-avatar-lg">VM</span>
        <div class="min-w-0 flex-1">
            <h2 class="text-xl font-bold text-zinc-900">{{ $vm['caller'] }}</h2>
            <p class="text-sm text-zinc-500 mt-0.5">
                {{ !empty($vm['date_time']) ? \Carbon\Carbon::parse($vm['date_time'])->format('M j, Y g:i A') : '—' }}
                · {{ $vm['status'] ?? 'unknown' }}
            </p>
        </div>
        @if (!empty($vm['caller_number']))
            @include('communications.partials.contact-quick-actions', [
                'routePrefix' => $routePrefix,
                'phone' => $vm['caller_number'],
            ])
        @endif
    </div>

    <section class="ghl-card">
        <h3 class="ghl-card-title">Voicemail</h3>
        @if (!empty($vm['transcription']))
            <p class="text-sm text-zinc-700 mb-4">{{ $vm['transcription'] }}</p>
        @endif
        @include('communications.partials.voicemail-actions', [
            'routePrefix' => $routePrefix,
            'fileId' => $vm['file_id'] ?? $vm['id'],
            'hasMedia' => $vm['has_media'] ?? false,
        ])
    </section>
@else
    @include('communications.inbox.partials.empty', [
        'title' => 'Voicemail not found',
        'message' => 'Select a voicemail from the list.',
    ])
@endif
