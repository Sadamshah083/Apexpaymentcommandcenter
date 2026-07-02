@php

    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');

    $mode = $mode ?? 'contacts';

    $tabs = [
        'contacts' => 'Contacts',

        'calls' => 'Calls',

        'queues' => 'Queues',

        'conferences' => 'Conferences',

        'leads' => 'Leads',

        'campaigns' => 'Campaigns',

        'lists' => 'Lists',

        'extensions' => 'Extensions',

        'dialer' => 'Dialer',

        'team' => 'Team',

        'settings' => 'Settings',
    ];

@endphp

<div class="ghl-hub-header">

    <div>

        <h1 class="ghl-hub-title">Communications Hub</h1>

        <p class="ghl-hub-subtitle">Morpheus CX — live calls, dialer, queues, conferences, and CRM</p>

    </div>

    <nav class="ghl-hub-nav ghl-hub-nav-scroll">

        @foreach ($tabs as $tabMode => $label)
            <a href="{{ route(
                $routePrefix . 'communications.index',
                match ($tabMode) {
                    'dialer' => ['panel' => 'dialer'],
                    'settings' => ['panel' => 'settings'],
                    'contacts' => ['channel' => 'inbox'],
                    default => ['channel' => $tabMode],
                },
            ) }}"
                class="ghl-hub-nav-link {{ $mode === $tabMode ? 'ghl-hub-nav-link-active' : '' }}">{{ $label }}</a>
        @endforeach

    </nav>

</div>
