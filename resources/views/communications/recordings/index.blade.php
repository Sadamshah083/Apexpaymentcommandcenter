@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Recordings')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'recordings', 'routePrefix' => $routePrefix])

    @foreach($warnings ?? [] as $warning)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
    @endforeach

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="ghl-calls-toolbar">
        <div>
            <h2 class="text-lg font-bold text-zinc-900">Call & meeting recordings</h2>
            <p class="text-sm text-zinc-500">{{ count($recordings) }} on this page · phone and cloud recordings</p>
        </div>
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="mode" value="recordings">
            <div>
                <label class="comm-hub-label block mb-1">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="comm-hub-input">
            </div>
            <div>
                <label class="comm-hub-label block mb-1">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="comm-hub-input">
            </div>
            <button type="submit" class="comm-hub-btn">Apply</button>
        </form>
    </div>

    <div class="ghl-call-feed">
        @forelse($recordings as $recording)
            <article class="ghl-call-card">
                <div class="ghl-call-card-main">
                    <span class="ghl-avatar">{{ strtoupper(substr($recording['file_type'] ?? 'R', 0, 1)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-zinc-900">{{ $recording['topic'] ?? 'Recording' }}</span>
                            <span class="ghl-tag">{{ $recording['source'] ?? 'phone' }}</span>
                            <span class="ghl-tag">{{ $recording['file_type'] ?? 'audio' }}</span>
                        </div>
                        <p class="text-sm text-zinc-500 mt-1">
                            {{ !empty($recording['start_time']) ? \Carbon\Carbon::parse($recording['start_time'])->format('M j, Y g:i A') : '—' }}
                            · {{ $recording['host'] ?? '—' }}
                            @if(!empty($recording['duration']))
                                · {{ (int) $recording['duration'] }}s
                            @endif
                        </p>
                    </div>
                </div>
                <div class="ghl-call-card-actions">
                    @if(!empty($recording['has_media']) && !empty($recording['id']))
                        @include('communications.partials.recording-actions', [
                            'routePrefix' => $routePrefix,
                            'recordingId' => $recording['id'],
                            'source' => $recording['source'] ?? 'phone',
                            'hasMedia' => true,
                        ])
                    @else
                        <span class="text-xs text-zinc-400">No media</span>
                    @endif
                </div>
            </article>
        @empty
            <div class="ghl-empty py-16">No recordings in this date range. Add cloud recording scopes in Settings if you expect meeting recordings.</div>
        @endforelse
    </div>

    @if($nextPageToken ?? null)
        <div class="mt-4 text-center">
            <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
               class="comm-hub-btn comm-hub-btn-secondary">Load more</a>
        </div>
    @endif
</div>

@include('communications.partials.audio-player')
@endsection

@push('scripts')
@include('communications.partials.audio-player-script')
@endpush
