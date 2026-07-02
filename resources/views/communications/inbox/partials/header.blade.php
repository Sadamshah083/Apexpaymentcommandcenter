<header class="ghl-inbox-header">
    <div class="ghl-inbox-header-main">
        <h1 class="ghl-inbox-title">Communications</h1>
        <p class="ghl-inbox-subtitle">Unified inbox for calls, SMS, voicemail, chat, and recordings</p>
    </div>

    <form method="GET" action="{{ route($routePrefix . 'communications.index') }}" class="ghl-inbox-search">
        <input type="hidden" name="channel" value="{{ $channel }}">
        @foreach (request()->only(['contact', 'session', 'call', 'voicemail', 'recording', 'chat_owner', 'chat_channel', 'chat_contact', 'filter', 'direction', 'status', 'from', 'to']) as $key => $value)
            @if (filled($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="search" name="search" value="{{ $filters['search'] ?? '' }}"
            placeholder="Search contacts, numbers, names…" class="ghl-search-input">
    </form>

    <div class="ghl-inbox-header-actions">
        <form method="GET" class="ghl-inbox-dates">
            <input type="hidden" name="channel" value="{{ $channel }}">
            @foreach (request()->only(['contact', 'session', 'search', 'filter', 'direction', 'status']) as $key => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <input type="date" name="from" value="{{ $filters['from'] }}" class="comm-hub-input comm-hub-input-sm"
                title="From date">
            <input type="date" name="to" value="{{ $filters['to'] }}" class="comm-hub-input comm-hub-input-sm"
                title="To date">
            <button type="submit" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Apply</button>
        </form>

        <a href="{{ route($routePrefix . 'communications.zoom.export.logs', ['from' => $filters['from'], 'to' => $filters['to']]) }}"
            class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Export</a>

        <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
            class="ghl-inbox-icon-btn {{ ($panel ?? '') === 'settings' ? 'ghl-inbox-icon-btn-active' : '' }}"
            title="Settings">⚙</a>

        <span
            class="comm-hub-badge {{ $connection['connected'] ?? false ? 'comm-hub-badge-success' : 'comm-hub-badge-warning' }}">
            {{ $connection['connected'] ?? false ? 'Live' : 'Offline' }}
        </span>
    </div>
</header>
