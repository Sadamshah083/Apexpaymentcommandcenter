@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $mode = $mode ?? 'contacts';
    $hubAccess = $hubAccess ?? [];
    $visibleChannels = $channels ?? [];

    $tabs = collect([
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
    ])->filter(function (string $label, string $tabMode) use ($visibleChannels, $hubAccess) {
        if ($tabMode === 'contacts') {
            return array_key_exists('inbox', $visibleChannels);
        }
        if ($tabMode === 'dialer') {
            return $hubAccess['canDial'] ?? true;
        }
        if ($tabMode === 'settings') {
            return $hubAccess['canConfigure'] ?? false;
        }

        return array_key_exists($tabMode, $visibleChannels);
    });
@endphp

<div class="ghl-hub-header">
    <div>
        <h1 class="ghl-hub-title">Communications Hub</h1>
        <p class="ghl-hub-subtitle">
            Morpheus CX — {{ $hubAccess['roleLabel'] ?? 'User' }} view
            @if (!($hubAccess['canConfigure'] ?? false))
                <span class="text-slate-400">(dial &amp; operate only)</span>
            @endif
        </p>
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
                class="ghl-hub-nav-link {{ $mode === $tabMode ? 'ghl-hub-nav-link-active' : '' }}"
                data-turbo="false">{{ $label }}</a>
        @endforeach
    </nav>
</div>
