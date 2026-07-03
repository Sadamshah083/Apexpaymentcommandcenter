@props([
    'workspaceManageRoute' => null,
    'logoutRoute',
])

<div class="app-floating-chrome" aria-label="Page controls">
    <button type="button" class="app-floating-menu-btn" data-sidebar-toggle aria-label="Open sidebar"
        aria-expanded="false" title="Menu">
        <span data-sidebar-mobile-icon aria-hidden="true">&#9776;</span>
    </button>

    <button type="button" id="push-btn" class="app-floating-notify-btn app-topnav-icon-btn"
        onclick="requestPushNotificationPermission()" aria-label="System notifications" title="System notifications">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        <span id="push-status-dot" class="app-topnav-icon-badge"></span>
    </button>
</div>

<div id="workspace-sync-indicator" class="app-sync-indicator-hidden" aria-hidden="true">
    <span class="app-topnav-status-dot" aria-hidden="true"></span>
    <span class="app-topnav-status-text">Live</span>
</div>
