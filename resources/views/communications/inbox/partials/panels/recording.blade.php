@if ($selectedRecording ?? null)
    @php $rec = $selectedRecording; @endphp
    <div class="ghl-detail-header">
        <span class="ghl-avatar ghl-avatar-lg">R</span>
        <div class="min-w-0 flex-1">
            <h2 class="text-xl font-bold text-zinc-900">{{ $rec['topic'] ?? 'Recording' }}</h2>
            <p class="text-sm text-zinc-500 mt-0.5">
                {{ !empty($rec['start_time']) ? \Carbon\Carbon::parse($rec['start_time'])->format('M j, Y g:i A') : '—' }}
                · {{ $rec['source'] ?? 'phone' }}
            </p>
        </div>
    </div>

    <section class="ghl-card">
        <h3 class="ghl-card-title">Playback</h3>
        @if (!empty($rec['has_media']) && !empty($rec['id']))
            @include('communications.partials.recording-actions', [
                'routePrefix' => $routePrefix,
                'recordingId' => $rec['id'],
                'callReferenceId' => $rec['call_reference_id'] ?? $rec['id'],
                'source' => $rec['source'] ?? 'phone',
                'hasMedia' => true,
            ])
        @else
            <p class="text-sm text-zinc-500">No playable media for this recording.</p>
        @endif
    </section>
@else
    @include('communications.inbox.partials.empty', [
        'title' => 'Recording not found',
        'message' => 'Select a recording from the list.',
    ])
@endif
