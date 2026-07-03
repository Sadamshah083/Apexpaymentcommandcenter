@props(['brandTitle' => null, 'brandSubtitle' => null, 'logoutRoute' => null])

@php
    $title = $brandTitle ?? config('app.name');
@endphp

<div class="app-sidebar-wrap">
    <div class="app-sidebar-backdrop" data-sidebar-backdrop hidden aria-hidden="true"></div>

    <aside class="app-sidebar" id="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-sidebar-brand">
                <img src="{{ asset('images/apexone-logo.png') }}" alt="{{ $title }}" class="app-sidebar-logo"
                    width="220" height="56">
                <img src="{{ asset('images/apexone-mark.png') }}" alt="{{ $title }}" class="app-sidebar-logo-mark"
                    width="40" height="40">
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            {!! $nav !!}
        </nav>

        @if ($logoutRoute)
            <div class="app-sidebar-footer">
                <div class="app-sidebar-footer-row">
                    @if ($brandSubtitle)
                        <span class="app-sidebar-role">{{ $brandSubtitle }}</span>
                    @endif
                    <form method="POST" action="{{ $logoutRoute }}" class="app-sidebar-logout-form">
                        @csrf
                        <button type="submit" class="app-sidebar-logout">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>Sign out</span>
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </aside>

    <button type="button" class="app-sidebar-edge-toggle" data-sidebar-toggle aria-label="Toggle sidebar"
        aria-expanded="true" title="Toggle sidebar">
        <span data-sidebar-edge-icon aria-hidden="true">&lsaquo;</span>
    </button>
</div>
