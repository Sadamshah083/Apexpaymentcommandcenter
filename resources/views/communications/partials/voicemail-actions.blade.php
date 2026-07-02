@if (!empty($fileId) && !empty($hasMedia))
    <div class="flex items-center gap-2">

        <button type="button" class="comm-hub-link comm-hub-play-btn"
            data-play-url="{{ route($routePrefix . 'communications.zoom.voicemails.media', ['fileId' => $fileId, 'action' => 'play']) }}">Play</button>

        <a href="{{ route($routePrefix . 'communications.zoom.voicemails.media', ['fileId' => $fileId, 'action' => 'download']) }}"
            class="comm-hub-link">Download</a>

    </div>
@else
    <span class="text-zinc-400">—</span>
@endif
