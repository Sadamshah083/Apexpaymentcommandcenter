<nav class="ghl-inbox-nav" aria-label="Channels">
    @foreach ($channels as $channelKey => $meta)
        @php
            $navQuery = array_filter([
                'channel' => $channelKey,
                'search' => request('search'),
                'filter' => request('filter'),
                'from' => request('from'),
                'to' => request('to'),
            ]);
            $count = $channelCounts[$channelKey] ?? ($channelCounts[$channelKey . '_total'] ?? null);
            $badge = match ($channelKey) {
                'calls' => $channelCounts['calls_missed'] ?? 0 ?: null,
                'voicemail' => $channelCounts['voicemail_unread'] ?? 0 ?: null,
                default => null,
            };
        @endphp
        <a href="{{ route($routePrefix . 'communications.index', $navQuery) }}"
            class="ghl-inbox-nav-item {{ $channel === $channelKey && !in_array($panel ?? '', ['settings', 'dialer']) ? 'ghl-inbox-nav-item-active' : '' }}"
            title="{{ $meta['label'] }}">
            @include('communications.inbox.partials.nav-icon', ['icon' => $meta['icon']])
            <span>{{ $meta['label'] }}</span>
            @if ($badge)
                <span class="ghl-inbox-nav-badge">{{ $badge > 99 ? '99+' : $badge }}</span>
            @endif
        </a>
    @endforeach

    <div class="ghl-inbox-nav-spacer"></div>

    <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'dialer'])) }}"
        class="ghl-inbox-nav-item ghl-inbox-nav-item-dial {{ ($panel ?? '') === 'dialer' ? 'ghl-inbox-nav-item-active' : '' }}"
        title="Phone dialer">
        @include('communications.inbox.partials.nav-icon', ['icon' => 'dial'])
        <span>Dial</span>
    </a>
</nav>
