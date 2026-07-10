<header class="ghl-comm-topbar ghl-inbox-toolbar">
    <div class="ghl-comm-topbar__left">
        <button type="button" class="ghl-comm-icon-btn" data-sidebar-toggle aria-label="Open menu" title="Menu">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="21" y2="12" /><line x1="3" y1="18" x2="21" y2="18" />
            </svg>
        </button>

        <h1 class="ghl-comm-topbar__title">Phone</h1>
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
