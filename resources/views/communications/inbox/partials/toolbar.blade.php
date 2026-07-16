@php
    $isDialerPanel = ($panel ?? '') === 'dialer'
        || (
            ($channel ?? '') === 'inbox'
            && blank($panel)
            && blank(request('contact'))
            && blank(request('call'))
            && blank(request('session'))
        );
    $settingsActive = ($panel ?? '') === 'settings';
@endphp

<header class="ghl-comm-topbar ghl-inbox-toolbar">
    <div class="ghl-comm-topbar__left">
        <button type="button" class="ghl-comm-icon-btn" data-sidebar-toggle aria-label="Open menu" title="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" />
            </svg>
        </button>

        <h1 class="ghl-comm-topbar__title">{{ $isDialerPanel ? 'Phone' : 'Conversations' }}</h1>

        @include('communications.inbox.partials.channels-menu')

        @if (!empty($channelCounts))
            @if (($channelCounts['calls_missed'] ?? 0) > 0)
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls', 'filter' => 'missed', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                    class="ghl-comm-pill">
                    Missed <strong>{{ $channelCounts['calls_missed'] }}</strong>
                </a>
            @endif
            @if (($channelCounts['voicemail_unread'] ?? 0) > 0)
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'voicemail', 'status' => 'unread', 'from' => $filters['from'], 'to' => $filters['to']]) }}"
                    class="ghl-comm-pill">
                    VM <strong>{{ $channelCounts['voicemail_unread'] }}</strong>
                </a>
            @endif
        @endif

        <details class="ghl-comm-dates">
            <summary class="ghl-comm-icon-btn" title="Date range" aria-label="Date range">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="18" rx="2" /><line x1="16" y1="2" x2="16" y2="6" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="3" y1="10" x2="21" y2="10" />
                </svg>
            </summary>
            <div class="ghl-comm-dates__menu">
                <form method="GET">
                    <input type="hidden" name="channel" value="{{ $channel }}">
                    @foreach (request()->only(['contact', 'session', 'search', 'filter', 'direction', 'status', 'call', 'voicemail', 'recording']) as $key => $value)
                        @if (filled($value))
                            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                        @endif
                    @endforeach
                    <label>From</label>
                    <input type="date" name="from" value="{{ $filters['from'] }}">
                    <label>To</label>
                    <input type="date" name="to" value="{{ $filters['to'] }}">
                    <button type="submit" class="ghl-comm-btn ghl-comm-btn--primary">Apply</button>
                </form>
            </div>
        </details>

        @if ($hubAccess['canConfigure'] ?? true)
            <form method="POST" action="{{ route($routePrefix . 'communications.zoom.refresh') }}" class="inline">
                @csrf
                <button type="submit" class="ghl-comm-icon-btn" title="Refresh data" aria-label="Refresh">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" />
                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
                    </svg>
                </button>
            </form>
        @endif

        <a href="{{ route($routePrefix . 'communications.zoom.export.logs', ['from' => $filters['from'], 'to' => $filters['to']]) }}"
            class="ghl-comm-btn ghl-comm-btn--success" title="Export logs" aria-label="Export">
            Export
        </a>

        @if ($hubAccess['canConfigure'] ?? true)
            <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
                class="ghl-comm-icon-btn {{ $settingsActive ? 'is-active' : '' }}"
                title="Settings" aria-label="Settings">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="3" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
                </svg>
            </a>
        @endif

        <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'inbox', 'panel' => 'dialer']) }}"
            class="ghl-comm-icon-btn ghl-comm-icon-btn--phone {{ $isDialerPanel ? 'is-active' : '' }}"
            title="Phone dialer" aria-label="Phone dialer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
            </svg>
        </a>
    </div>

    <div class="ghl-comm-topbar__actions">
        @include('communications.partials.global-line-picker', [
            'routePrefix' => $routePrefix,
            'morpheusExtensions' => $morpheusExtensions ?? [],
            'phoneUsers' => $phoneUsers ?? [],
            'defaultCallerId' => $defaultCallerId ?? null,
            'connection' => $connection ?? [],
            'placement' => 'toolbar',
        ])
    </div>
</header>
