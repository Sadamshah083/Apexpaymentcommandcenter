@if (!empty($recordingId) && !empty($hasMedia))
    @php
        $mediaQuery = array_filter([
            'source' => $source ?? 'phone',
            'action' => 'play',
            'call_ref' => $callReferenceId ?? null,
        ]);
        $downloadQuery = array_filter([
            'source' => $source ?? 'phone',
            'action' => 'download',
            'call_ref' => $callReferenceId ?? null,
        ]);
    @endphp
    <div class="flex items-center gap-2">
        <button type="button" class="comm-hub-link comm-hub-play-btn"
            data-play-url="{{ route($routePrefix . 'communications.zoom.recordings.media', array_merge(['recordingId' => $recordingId], $mediaQuery)) }}">Play</button>
        <a href="{{ route($routePrefix . 'communications.zoom.recordings.media', array_merge(['recordingId' => $recordingId], $downloadQuery)) }}"
            class="comm-hub-link">Download</a>
    </div>
@else
    <span class="text-slate-400">—</span>
@endif
