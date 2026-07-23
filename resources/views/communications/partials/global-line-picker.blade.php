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
        @php
            // Dialer login should never show Offline/Off when Morpheus is configured —
            // the badge means the agent session is live, not a stale probe cache.
            $morpheusConfigured = app(\App\Services\Integrations\ZoomApiService::class)->isConfigured();
            $lineLive = $morpheusConfigured || (bool) ($connection['connected'] ?? false);
        @endphp
        <span class="ghl-comm-global-line__status ghl-comm-live {{ $lineLive ? 'ghl-comm-live--on' : 'ghl-comm-live--off' }}"
            data-comm-live-badge
            title="{{ $lineLive ? 'You are logged in — line ready' : 'Morpheus telephony is not configured' }}">
            <span class="ghl-comm-live-dot" aria-hidden="true"></span>
            <span data-comm-live-label>{{ $lineLive ? 'Connected' : 'Off' }}</span>
        </span>
    @endif

</div>

