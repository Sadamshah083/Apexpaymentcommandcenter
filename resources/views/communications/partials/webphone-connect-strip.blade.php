@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $align = $align ?? 'right';
    $callHistoryUrl = $callHistoryUrl ?? route($routePrefix . 'communications.index', ['channel' => 'calls']);
@endphp

<div class="ghl-comm-connect-strip ghl-comm-connect-strip--{{ $align }}">
    @include('communications.partials.webphone-panel', [
        'routePrefix' => $routePrefix,
        'defaultCallerId' => $defaultCallerId ?? null,
        'layout' => 'minimal',
        'callHistoryUrl' => $callHistoryUrl,
    ])
</div>
