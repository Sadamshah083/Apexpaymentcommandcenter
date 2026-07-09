@php
    $navLabel = function (string $channelKey, array $meta) {
        return match ($channelKey) {
            'sms' => 'Messages',
            'chat' => 'Chat',
            'agents' => 'Agents',
            'recordings' => 'Record',
            'conferences' => 'Conf',
            'voicemail' => 'VM',
            default => $meta['label'],
        };
    };
@endphp

<details class="ghl-comm-channels-menu">
    <summary class="ghl-comm-icon-btn" title="All channels" aria-label="All channels">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
    </summary>
    <div class="ghl-comm-channels-menu__dropdown">
        @foreach ($channels as $channelKey => $meta)
            @php
                $navQuery = array_filter([
                    'channel' => $channelKey,
                    'search' => request('search'),
                    'filter' => request('filter'),
                    'from' => request('from'),
                    'to' => request('to'),
                ]);
            @endphp
            <a href="{{ route($routePrefix . 'communications.index', $navQuery) }}"
                class="ghl-comm-channels-menu__item {{ ($channel ?? '') === $channelKey && ($panel ?? '') !== 'dialer' ? 'is-active' : '' }}">
                {{ $navLabel($channelKey, $meta) }}
            </a>
        @endforeach
        <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'dialer', 'channel' => 'inbox'])) }}"
            class="ghl-comm-channels-menu__item {{ ($panel ?? '') === 'dialer' ? 'is-active' : '' }}">
            Phone
        </a>
        @if ($hubAccess['canConfigure'] ?? false)
            <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
                class="ghl-comm-channels-menu__item {{ ($panel ?? '') === 'settings' ? 'is-active' : '' }}">
                Settings
            </a>
        @endif
    </div>
</details>
