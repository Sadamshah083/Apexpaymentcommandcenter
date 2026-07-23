<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="auth-login-paths" content="{{ json_encode([route('admin.login', [], false), route('portal.login', [], false), '/login']) }}">
    <title>@yield('title', 'Dashboard') - {{ config('app.name') }}</title>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('apex-ui-theme') || localStorage.getItem('communications.dialer_theme') || 'light';
                t = t === 'dark' ? 'dark' : 'light';
                document.documentElement.dataset.theme = t;
                document.documentElement.dataset.commTheme = t;
                document.documentElement.classList.toggle('theme-dark', t === 'dark');
                document.documentElement.classList.toggle('theme-light', t === 'light');
            } catch (e) {}
        })();
    </script>
    @include('layouts.partials.favicon')
    @include('layouts.partials.critical-head')
    @include('layouts.partials.google-fonts')
    @include('layouts.partials.vite-assets')
</head>

{{-- Turbo prefetch = Next.js <Link> style hover/idle warm cache for same-origin nav --}}
<body class="app-shell min-h-screen font-sans antialiased" data-turbo-prefetch="true"
    @auth
data-workspace-id="{{ auth()->user()->current_workspace_id }}"
        data-presence-url="{{ route('portal.communications.monitoring.presence') }}"
        data-workspace-sync-scope="{{
            request()->routeIs('portal.communications.*')
                ? 'off'
                : (request()->routeIs('portal.lists.*', 'portal.deliverability.*', 'portal.content.*', 'portal.reputation.*')
                    ? 'lite'
                    : (request()->routeIs('portal.setter.*', 'portal.closer.*', 'portal.setter-team.*', 'portal.closer-team.*', 'portal.pipeline*', 'portal.performance*', 'portal.leads*')
                        ? 'full'
                        : 'off'))
        }}"
        data-workspace-sync-url="{{ route('portal.sync.poll') }}"
        data-workspace-sync-stream-url="{{ route('portal.sync.stream') }}"
        {{-- Poll (not SSE stream) so long-lived sync never blocks Turbo navigation --}}
        data-workspace-sync-use-poll="1"
        data-lead-show-base="{{ url('/portal/leads') }}"
        data-workflow-show-base="{{ url('/portal/leads') }}"
        data-push-vapid-key-url="{{ route('portal.push.vapid') }}"
        data-push-subscribe-url="{{ route('portal.push.subscribe') }}"
        data-push-test-url="{{ route('portal.push.test') }}" @endauth>
    @include('layouts.partials.sidebar-state-boot')
    <div class="flex min-h-screen">
        @include('layouts.partials.sidebar-shell', [
            'brandTitle' => config('app.name'),
            'brandSubtitle' => 'Agent',
            'logoutRoute' => route('portal.logout'),
            'nav' => view('layouts.partials.sidebar-nav-portal'),
        ])

        <div class="app-content-shell">
            @auth
                @include('layouts.partials.topnav', [
                    'workspaceManageRoute' => null,
                    'logoutRoute' => route('portal.logout'),
                ])
            @endauth

            <main class="app-main">
                @include('layouts.partials.app-main')
            </main>
        </div>
    </div>
    @include('layouts.partials.toasts')
    @include('layouts.partials.deployment-notice')
    @stack('scripts')
</body>

</html>
