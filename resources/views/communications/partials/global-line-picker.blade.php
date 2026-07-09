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

        ])

    </div>

    @include('communications.partials.webphone-connect-strip', [

        'routePrefix' => $routePrefix,

        'defaultCallerId' => $defaultCallerId ?? null,

        'align' => 'right',

        'callHistoryUrl' => route($routePrefix . 'communications.index', ['channel' => 'calls']),

    ])

</div>

