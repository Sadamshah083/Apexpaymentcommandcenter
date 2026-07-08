@php
    $roleLabel = $hubAccess['roleLabel'] ?? 'User';
    $isDialerPanel = ($panel ?? '') === 'dialer';
    $channelChips = [];
    foreach (($channels ?? []) as $key => $meta) {
        $channelChips[] = [
            'key' => (string) $key,
            'label' => (string) ($meta['label'] ?? ucfirst((string) $key)),
        ];
    }
    $channelChips[] = ['key' => 'phone', 'label' => 'Phone'];
@endphp

<header class="ghl-inbox-toolbar ch-hub-toolbar-enterprise {{ $isDialerPanel ? 'ch-hub-toolbar--dialer' : '' }}">
    @if ($isDialerPanel)
        <div class="ghl-inbox-toolbar-top ghl-inbox-toolbar-top--dialer">
            <div class="ghl-inbox-toolbar-brand ghl-inbox-toolbar-brand--dialer">
                <h1 class="ghl-inbox-toolbar-heading">Communications Hub</h1>
                <p class="ghl-inbox-toolbar-sub ghl-inbox-toolbar-sub--dialer">
                    Three steps: connect your browser line, enter a number, then talk. Calls route through your Morpheus campaign trunk.
                </p>
            </div>
        </div>
        <div class="ghl-inbox-toolbar-channels" aria-label="Available channels">
            @foreach ($channelChips as $chip)
                @php
                    $isActiveChip = $chip['key'] === 'phone' ? $isDialerPanel : ($chip['key'] === ($channel ?? ''));
                @endphp
                <span class="ghl-inbox-toolbar-chip {{ $isActiveChip ? 'is-active' : '' }}">{{ $chip['label'] }}</span>
            @endforeach
        </div>
    @else
        <div class="ghl-inbox-toolbar-top">
            <div class="ghl-inbox-toolbar-brand">
                <div class="ghl-inbox-toolbar-brand-row">
                    <div>
                        <h1 class="ghl-inbox-toolbar-heading">Communications Hub</h1>
                        <p class="ghl-inbox-toolbar-sub">
                            <span class="ghl-inbox-toolbar-channel">{{ $channelLabel }}</span>
                            <span class="ghl-inbox-toolbar-dot" aria-hidden="true">·</span>
                            {{ $roleLabel }}
                        </p>
                    </div>
                </div>
            </div>

            <form method="GET" action="{{ route($routePrefix . 'communications.index') }}" class="ghl-inbox-toolbar-search">
                <input type="hidden" name="channel" value="{{ $channel }}">
                @foreach (request()->only(['contact', 'session', 'call', 'voicemail', 'recording', 'chat_owner', 'chat_channel', 'chat_contact', 'filter', 'direction', 'status', 'from', 'to']) as $key => $value)
                    @if (filled($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <svg class="ghl-inbox-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    aria-hidden="true">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.3-4.3" />
                </svg>
                <input type="search" name="search" value="{{ $filters['search'] ?? '' }}"
                    placeholder="Search contacts, numbers…" class="ghl-search-input" aria-label="Search">
            </form>

            <div class="ghl-inbox-toolbar-status">
                @if (!empty($channelCounts))
                    <div class="ghl-inbox-stat-pills">
                        @if (($channelCounts['calls_missed'] ?? 0) > 0)
                            <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls', 'filter' => 'missed', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                                class="ghl-inbox-stat-pill ghl-inbox-stat-pill-warn">
                                Missed <strong>{{ $channelCounts['calls_missed'] }}</strong>
                            </a>
                        @endif
                        @if (($channelCounts['voicemail_unread'] ?? 0) > 0)
                            <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'voicemail', 'status' => 'unread', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                                class="ghl-inbox-stat-pill ghl-inbox-stat-pill-warn">
                                Voicemail <strong>{{ $channelCounts['voicemail_unread'] }}</strong>
                            </a>
                        @endif
                    </div>
                @endif

                <span
                    class="ch-badge {{ ($connection['connected'] ?? false) ? 'ch-badge--live' : 'ch-badge--warn' }}">
                    {{ $connection['connected'] ?? false ? 'Live' : 'Offline' }}
                </span>
            </div>
        </div>

        <div class="ghl-inbox-toolbar-controls">
            <form method="GET" class="ghl-inbox-dates">
                <input type="hidden" name="channel" value="{{ $channel }}">
                @foreach (request()->only(['contact', 'session', 'search', 'filter', 'direction', 'status']) as $key => $value)
                    @if (filled($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <span class="ghl-inbox-dates-label">Date range</span>
                <label class="ghl-inbox-date-label">
                    <span class="sr-only">From</span>
                    <input type="date" name="from" value="{{ $filters['from'] }}"
                        class="comm-hub-input comm-hub-input-sm" title="From date">
                </label>
                <span class="ghl-inbox-date-sep">–</span>
                <label class="ghl-inbox-date-label">
                    <span class="sr-only">To</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}"
                        class="comm-hub-input comm-hub-input-sm" title="To date">
                </label>
                <button type="submit" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Apply</button>
            </form>

            <div class="ghl-inbox-toolbar-buttons">
                @if ($hubAccess['canConfigure'] ?? true)
                    <form method="POST" action="{{ route($routePrefix . 'communications.zoom.refresh') }}">
                        @csrf
                        <button type="submit" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm"
                            title="Refresh Morpheus CX data">Refresh</button>
                    </form>
                @endif

                <a href="{{ route($routePrefix . 'communications.zoom.export.logs', ['from' => $filters['from'], 'to' => $filters['to']]) }}"
                    class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Export</a>

                @if ($hubAccess['canConfigure'] ?? true)
                    <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
                        class="ghl-inbox-icon-btn {{ ($panel ?? '') === 'settings' ? 'ghl-inbox-icon-btn-active' : '' }}"
                        title="Settings" aria-label="Settings">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </a>
                @endif
            </div>
        </div>
        <div class="ghl-inbox-toolbar-channels" aria-label="Available channels">
            @foreach ($channelChips as $chip)
                @php
                    $isActiveChip = $chip['key'] === 'phone' ? (($panel ?? '') === 'dialer') : ($chip['key'] === ($channel ?? ''));
                @endphp
                <span class="ghl-inbox-toolbar-chip {{ $isActiveChip ? 'is-active' : '' }}">{{ $chip['label'] }}</span>
            @endforeach
        </div>
    @endif
</header>
