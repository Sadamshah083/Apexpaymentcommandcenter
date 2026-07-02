@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Voicemails')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'voicemails', 'routePrefix' => $routePrefix])

    @if($warning)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
    @endif

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="ghl-calls-toolbar">
        <div>
            <h2 class="text-lg font-bold text-zinc-900">Voicemails</h2>
            <p class="text-sm text-zinc-500">{{ count($voiceMails) }} on this page</p>
        </div>
        <form method="GET" class="flex flex-wrap gap-2 items-end">
            <input type="hidden" name="mode" value="voicemails">
            <div>
                <label class="comm-hub-label block mb-1">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="comm-hub-input">
            </div>
            <div>
                <label class="comm-hub-label block mb-1">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="comm-hub-input">
            </div>
            <div>
                <label class="comm-hub-label block mb-1">Status</label>
                <select name="status" class="comm-hub-input">
                    @foreach(['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label)
                        <option value="{{ $value }}" {{ ($filters['status'] ?? 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="comm-hub-btn">Apply</button>
        </form>
    </div>

    <div class="ghl-call-feed">
        @forelse($voiceMails as $vm)
            <article class="ghl-call-card">
                <div class="ghl-call-card-main">
                    <span class="ghl-avatar">VM</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-zinc-900">{{ $vm['caller'] }}</span>
                            <span class="text-zinc-400">→</span>
                            <span class="font-semibold text-zinc-900">{{ $vm['callee'] }}</span>
                            <span class="ghl-tag {{ ($vm['status'] ?? '') === 'unread' ? 'ghl-tag-unread' : '' }}">{{ $vm['status'] ?? 'unknown' }}</span>
                        </div>
                        <p class="text-sm text-zinc-500 mt-1">
                            {{ !empty($vm['date_time']) ? \Carbon\Carbon::parse($vm['date_time'])->format('M j, Y g:i A') : '—' }}
                            @if(!empty($vm['duration']))
                                · {{ (int) $vm['duration'] }}s
                            @endif
                            @if(!empty($vm['caller_number']))
                                · {{ $vm['caller_number'] }}
                            @endif
                        </p>
                        @if(!empty($vm['transcription']))
                            <p class="text-xs text-zinc-600 mt-2 line-clamp-2">{{ $vm['transcription'] }}</p>
                        @endif
                    </div>
                </div>
                <div class="ghl-call-card-actions">
                    @if(!empty($vm['caller_number']))
                        @include('communications.partials.call-actions', [
                            'routePrefix' => $routePrefix,
                            'phone' => $vm['caller_number'],
                        ])
                    @endif
                    @include('communications.partials.voicemail-actions', [
                        'routePrefix' => $routePrefix,
                        'fileId' => $vm['file_id'] ?? $vm['id'],
                        'hasMedia' => $vm['has_media'] ?? false,
                    ])
                </div>
            </article>
        @empty
            <div class="ghl-empty py-16">
                <p>No voicemails in this date range.</p>
                @if(empty($error))
                    <p class="text-sm text-zinc-500 mt-2 max-w-md mx-auto">
                        Voicemail playback is not available through the Morpheus CX Call-Control API.
                    </p>
                @endif
            </div>
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
