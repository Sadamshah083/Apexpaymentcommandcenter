<header class="ghl-inbox-toolbar">
    <span class="ghl-inbox-toolbar-title">{{ $channelLabel }}</span>

    <form method="GET" action="{{ route($routePrefix.'communications.index') }}" class="ghl-inbox-toolbar-search">
        <input type="hidden" name="channel" value="{{ $channel }}">
        @foreach(request()->only(['contact', 'session', 'call', 'voicemail', 'recording', 'chat_owner', 'chat_channel', 'chat_contact', 'filter', 'direction', 'status', 'from', 'to']) as $key => $value)
            @if(filled($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <svg class="ghl-inbox-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search contacts, numbers…" class="ghl-search-input">
    </form>

    @if(!empty($channelCounts))
        <div class="ghl-inbox-stat-pills">
            @if(($channelCounts['calls_missed'] ?? 0) > 0)
                <a href="{{ route($routePrefix.'communications.index', ['channel' => 'calls', 'filter' => 'missed', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                   class="ghl-inbox-stat-pill ghl-inbox-stat-pill-warn">
                    Missed <strong>{{ $channelCounts['calls_missed'] }}</strong>
                </a>
            @endif
            @if(($channelCounts['voicemail_unread'] ?? 0) > 0)
                <a href="{{ route($routePrefix.'communications.index', ['channel' => 'voicemail', 'status' => 'unread', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                   class="ghl-inbox-stat-pill ghl-inbox-stat-pill-warn">
                    VM <strong>{{ $channelCounts['voicemail_unread'] }}</strong>
                </a>
            @endif
            <span class="ghl-inbox-stat-pill">Calls <strong>{{ $channelCounts['calls_total'] ?? 0 }}</strong></span>
            <span class="ghl-inbox-stat-pill">SMS <strong>{{ $channelCounts['sms'] ?? 0 }}</strong></span>
        </div>
    @endif

    <div class="ghl-inbox-toolbar-actions">
        <form method="GET" class="ghl-inbox-dates">
            <input type="hidden" name="channel" value="{{ $channel }}">
            @foreach(request()->only(['contact', 'session', 'search', 'filter', 'direction', 'status']) as $key => $value)
                @if(filled($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input type="date" name="from" value="{{ $filters['from'] }}" class="comm-hub-input comm-hub-input-sm" title="From">
            <input type="date" name="to" value="{{ $filters['to'] }}" class="comm-hub-input comm-hub-input-sm" title="To">
            <button type="submit" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Apply</button>
        </form>

        <form method="POST" action="{{ route($routePrefix.'communications.zoom.refresh') }}">
            @csrf
            <button type="submit" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm" title="Refresh Zoom data">Refresh</button>
        </form>

        <a href="{{ route($routePrefix.'communications.zoom.export.logs', ['from' => $filters['from'], 'to' => $filters['to']]) }}"
           class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Export</a>

        <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
           class="ghl-inbox-icon-btn {{ ($panel ?? '') === 'settings' ? 'ghl-inbox-icon-btn-active' : '' }}" title="Settings">⚙</a>

        <span class="comm-hub-badge {{ ($connection['connected'] ?? false) ? 'comm-hub-badge-success' : 'comm-hub-badge-warning' }}">
            {{ ($connection['connected'] ?? false) ? 'Live' : 'Offline' }}
        </span>
    </div>
</header>
