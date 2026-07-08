@php
    $navQuery = array_filter([
        'channel' => $channelKey,
        'search' => request('search'),
        'filter' => request('filter'),
        'from' => request('from'),
        'to' => request('to'),
    ]);
    $badge = match ($channelKey) {
        'calls' => $channelCounts['calls_missed'] ?? 0 ?: null,
        'voicemail' => $channelCounts['voicemail_unread'] ?? 0 ?: null,
        default => null,
    };
    $isActive = ($channel ?? 'inbox') === $channelKey
        && ! in_array($panel ?? '', ['settings', 'dialer'], true);
@endphp
<a href="{{ route($routePrefix . 'communications.index', $navQuery) }}"
    class="ghl-inbox-nav-item {{ $isActive ? 'ghl-inbox-nav-item-active' : '' }}"
    title="{{ $meta['label'] }}">
    @include('communications.inbox.partials.nav-icon', ['icon' => $meta['icon']])
    <span class="ghl-inbox-nav-label">{{ $meta['label'] }}</span>
    @if ($badge)
        <span class="ghl-inbox-nav-badge">{{ $badge > 99 ? '99+' : $badge }}</span>
    @endif
</a>
