@php

    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');

    $mode = $mode ?? 'contacts';

    $tabs = [

        'contacts' => 'Contacts',

        'calls' => 'Calls',

        'dialer' => 'Dialer',

        'recordings' => 'Recordings',

        'voicemails' => 'Voicemails',

        'sms' => 'SMS',

        'chat' => 'Chat',

        'team' => 'Team',

        'settings' => 'Settings',

    ];

@endphp



<div class="ghl-hub-header">

    <div>

        <h1 class="ghl-hub-title">Communications Hub</h1>

        <p class="ghl-hub-subtitle">Zoom Phone contacts, dialer, calls, recordings, voicemails, SMS, and Team Chat</p>

    </div>

    <nav class="ghl-hub-nav ghl-hub-nav-scroll">

        @foreach($tabs as $tabMode => $label)

            <a href="{{ route($routePrefix.'communications.index', ['mode' => $tabMode]) }}"

               class="ghl-hub-nav-link {{ $mode === $tabMode ? 'ghl-hub-nav-link-active' : '' }}">{{ $label }}</a>

        @endforeach

    </nav>

</div>


