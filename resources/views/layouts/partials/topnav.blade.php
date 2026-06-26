@props([
    'workspaceManageRoute' => null,
    'logoutRoute',
])

@php
    $user = Auth::user();
    $workspace = $user?->currentWorkspace;
    $switchableWorkspaces = $user?->switchableWorkspaces() ?? collect();
    $workspaceSwitchRoute = $workspaceManageRoute
        ? 'admin.workspaces.switch'
        : 'portal.workspaces.switch';
    $nameParts = preg_split('/\s+/', trim($user?->name ?? ''));
    $initials = strtoupper(
        substr($nameParts[0] ?? 'U', 0, 1).
        (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : '')
    );
@endphp

<header class="app-topnav">
    <div class="app-topnav-left">
        <div id="workspace-sync-indicator" class="app-topnav-status" title="Real-time workspace sync">
            <span class="app-topnav-status-dot" aria-hidden="true"></span>
            <span class="app-topnav-status-text">Live</span>
        </div>
    </div>

    <div class="app-topnav-right">
        @if($workspace)
            <details class="app-topnav-dropdown">
                <summary class="app-topnav-btn app-topnav-btn-workspace">
                    <svg class="app-topnav-btn-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h-2m-12 0H3m2-16h10M9 7h1m-1 4h1m4-4h1m-1 4h1"/>
                    </svg>
                    <span class="app-topnav-btn-label">{{ $workspace->name }}</span>
                    <svg class="app-topnav-btn-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </summary>
                <div class="app-topnav-panel">
                    <div class="app-topnav-panel-header">Switch workspace</div>
                    @forelse($switchableWorkspaces as $ws)
                        @if($ws->id === $user->current_workspace_id)
                            <div class="app-topnav-panel-item app-topnav-panel-item-active">
                                <span>{{ $ws->name }}</span>
                                <span class="app-topnav-panel-item-badge">Active</span>
                            </div>
                        @else
                            <form method="POST" action="{{ route($workspaceSwitchRoute, $ws->id) }}">
                                @csrf
                                <button type="submit" class="app-topnav-panel-item app-topnav-panel-item-btn">{{ $ws->name }}</button>
                            </form>
                        @endif
                    @empty
                        <div class="app-topnav-panel-item app-topnav-panel-item-muted">No workspaces available</div>
                    @endforelse
                    @if($workspaceManageRoute)
                        <div class="app-topnav-panel-divider"></div>
                        <a href="{{ $workspaceManageRoute }}" class="app-topnav-panel-item">Manage workspace</a>
                    @endif
                </div>
            </details>
        @endif

        <button
            type="button"
            id="push-btn"
            class="app-topnav-icon-btn"
            onclick="requestPushNotificationPermission()"
            aria-label="System notifications"
            title="System notifications"
        >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span id="push-status-dot" class="app-topnav-icon-badge"></span>
        </button>

        <details class="app-topnav-dropdown">
            <summary class="app-topnav-btn app-topnav-btn-user">
                <span class="app-topnav-avatar" aria-hidden="true">{{ $initials }}</span>
                <span class="app-topnav-btn-label">{{ $user->name }}</span>
                <svg class="app-topnav-btn-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </summary>
            <div class="app-topnav-panel app-topnav-panel-user">
                <div class="app-topnav-user-card">
                    <span class="app-topnav-avatar app-topnav-avatar-lg">{{ $initials }}</span>
                    <div class="app-topnav-user-meta">
                        <div class="app-topnav-user-name">{{ $user->name }}</div>
                        <div class="app-topnav-user-email">{{ $user->email }}</div>
                    </div>
                </div>
                <div class="app-topnav-panel-divider"></div>
                <form method="POST" action="{{ $logoutRoute }}">
                    @csrf
                    <button type="submit" class="app-topnav-panel-item app-topnav-panel-item-btn app-topnav-panel-signout">Sign out</button>
                </form>
            </div>
        </details>
    </div>
</header>
