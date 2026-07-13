@php

    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');

    $placement = $placement ?? 'toolbar';

@endphp



<div class="ghl-comm-global-line ghl-comm-global-line--{{ $placement }}" id="ghl-comm-global-line">

    <div class="ghl-comm-global-line__ext">

        @include('communications.partials.dialer-extension-field', [
            'routePrefix' => $routePrefix,
            'morpheusExtensions' => $morpheusExtensions ?? [],
            'phoneUsers' => $phoneUsers ?? [],
            'defaultCallerId' => $defaultCallerId ?? null,
            'callerSelectId' => 'dial-caller-id-global',
            'triggerStyle' => ($placement ?? 'toolbar') === 'toolbar' ? 'toolbar' : 'default',
        ])

    </div>

    @include('communications.partials.webphone-connect-strip', [

        'routePrefix' => $routePrefix,

        'defaultCallerId' => $defaultCallerId ?? null,

        'align' => 'right',

        'callHistoryUrl' => route($routePrefix . 'communications.index'),

    ])

    @if (($placement ?? 'toolbar') === 'toolbar')
        <span class="ghl-comm-global-line__status ghl-comm-live {{ ($connection['connected'] ?? false) ? 'ghl-comm-live--on' : 'ghl-comm-live--off' }}"
            title="{{ ($connection['connected'] ?? false) ? 'Morpheus telephony is connected' : 'Morpheus telephony is offline' }}">
            <span class="ghl-comm-live-dot" aria-hidden="true"></span>
            {{ ($connection['connected'] ?? false) ? 'Live' : 'Off' }}
        </span>
    @endif

</div>

