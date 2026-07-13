<header class="ghl-comm-topbar ghl-inbox-toolbar ghl-comm-topbar--dialer">
    <div class="ghl-comm-topbar__left">
        <h1 class="ghl-comm-topbar__title">Communications Dialer</h1>
    </div>

    <div class="ghl-comm-topbar__actions">
        @include('communications.partials.global-line-picker', [
            'routePrefix' => $routePrefix,
            'morpheusExtensions' => $morpheusExtensions ?? [],
            'phoneUsers' => $phoneUsers ?? [],
            'defaultCallerId' => $defaultCallerId ?? null,
            'connection' => $connection ?? [],
            'placement' => 'toolbar',
        ])

        <div class="ghl-comm-topbar__notes">
            @include('communications.partials.phone-notes-panel', [
                'routePrefix' => $routePrefix,
            ])
        </div>
    </div>
</header>
