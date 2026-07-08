@php
    use App\Services\Communications\CommunicationsAccessService;
    use App\Services\Communications\CommunicationsInboxService;

    $orderedKeys = array_keys(CommunicationsInboxService::CHANNELS);
    $visibleKeys = array_values(array_intersect($orderedKeys, array_keys($channels)));
    $primaryKeys = array_values(array_intersect(CommunicationsAccessService::PRIMARY_NAV_CHANNELS, $visibleKeys));
    $secondaryKeys = array_values(array_diff($visibleKeys, $primaryKeys));
@endphp

<div class="ghl-inbox-nav-wrap">
    <nav class="ghl-inbox-nav ghl-inbox-nav-primary" aria-label="Primary channels">
        @foreach ($primaryKeys as $channelKey)
            @include('communications.inbox.partials.nav-item', [
                'channelKey' => $channelKey,
                'meta' => $channels[$channelKey],
            ])
        @endforeach

        <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'inbox', 'panel' => 'dialer']) }}"
            class="ghl-inbox-nav-item ghl-inbox-nav-item-dial {{ ($panel ?? '') === 'dialer' ? 'ghl-inbox-nav-item-active' : '' }}"
            title="Phone dialer">
            <span class="ghl-inbox-nav-label">Phone</span>
        </a>
    </nav>

    @if ($secondaryKeys !== [])
        <nav class="ghl-inbox-nav ghl-inbox-nav-secondary ghl-inbox-nav-secondary-inline" aria-label="More channels">
            <span class="ghl-inbox-nav-divider" aria-hidden="true"></span>
            @foreach ($secondaryKeys as $channelKey)
                @include('communications.inbox.partials.nav-item', [
                    'channelKey' => $channelKey,
                    'meta' => $channels[$channelKey],
                ])
            @endforeach
        </nav>

        <details class="ghl-inbox-nav-more">
            <summary class="ghl-inbox-nav-more-toggle" aria-label="More channels">
                <span class="ghl-inbox-nav-label">More</span>
            </summary>
            <nav class="ghl-inbox-nav ghl-inbox-nav-secondary ghl-inbox-nav-secondary-menu" aria-label="More channels">
                @foreach ($secondaryKeys as $channelKey)
                    @include('communications.inbox.partials.nav-item', [
                        'channelKey' => $channelKey,
                        'meta' => $channels[$channelKey],
                    ])
                @endforeach
            </nav>
        </details>
    @endif
</div>
