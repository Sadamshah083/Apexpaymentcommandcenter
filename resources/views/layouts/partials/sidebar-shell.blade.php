@props(['brandTitle' => null, 'brandSubtitle' => null, 'logoutRoute' => null])

@php
    $title = $brandTitle ?? config('app.name');
    $userEmail = auth()->user()?->email;
@endphp

<div class="app-sidebar-wrap" id="app-sidebar-root" data-turbo-permanent>
    <div class="app-sidebar-backdrop" data-sidebar-backdrop hidden aria-hidden="true"></div>

    <aside class="app-sidebar" id="app-sidebar">
        <div class="app-sidebar-header">
            <div class="app-sidebar-brand">
                <img src="{{ asset('images/apexone-logo.png') }}" alt="{{ $title }}"
                    class="app-sidebar-logo app-sidebar-logo--light" width="220" height="56">
                <img src="{{ asset('images/apexone-logo-dark.png') }}" alt="{{ $title }}"
                    class="app-sidebar-logo app-sidebar-logo--dark" width="220" height="56">
                <img src="{{ asset('images/apexone-mark.png') }}" alt="{{ $title }}"
                    class="app-sidebar-logo-mark" width="40" height="40">
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            {!! $nav !!}
        </nav>

        <div class="app-sidebar-footer">
            @if (filled($userEmail))
                <div class="app-sidebar-user" title="{{ $userEmail }}">
                    <span class="app-sidebar-user-email">{{ $userEmail }}</span>
                </div>
            @endif

            <button type="button"
                class="app-sidebar-theme-toggle app-sidebar-theme-toggle--footer"
                data-theme-toggle
                aria-label="Toggle light and dark mode"
                title="Toggle light / dark mode">
                <span class="app-sidebar-theme-toggle__icon" aria-hidden="true">
                    <svg class="app-sidebar-theme-toggle__sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                    </svg>
                    <svg class="app-sidebar-theme-toggle__moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                            d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                    </svg>
                </span>
                <span data-theme-label>Dark</span>
            </button>

            @if ($logoutRoute)
                <div class="app-sidebar-footer-row">
                    @if ($brandSubtitle)
                        <span class="app-sidebar-role">{{ $brandSubtitle }}</span>
                    @endif
                    <form method="POST" action="{{ $logoutRoute }}" class="app-sidebar-logout-form" data-turbo="false">
                        @csrf
                        <button type="submit" class="app-sidebar-logout" data-turbo="false">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>Sign out</span>
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </aside>

    <button type="button" class="app-sidebar-edge-toggle" data-sidebar-toggle aria-controls="app-sidebar"
        aria-label="Toggle sidebar" aria-expanded="true" title="Toggle sidebar">
        <span data-sidebar-edge-icon aria-hidden="true">&lsaquo;</span>
    </button>
</div>
