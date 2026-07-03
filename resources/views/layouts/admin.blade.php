<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="auth-login-paths" content="{{ json_encode([route('admin.login', [], false), route('portal.login', [], false), '/login']) }}">
    <title>@yield('title', 'Dashboard') - {{ config('app.name') }}</title>
    @include('layouts.partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="app-shell min-h-screen font-sans antialiased" data-turbo-prefetch="false"
    @auth
data-workspace-id="{{ auth()->user()->current_workspace_id }}"
        data-workspace-sync-scope="{{ request()->routeIs('admin.dashboard*') ? 'list' : (request()->routeIs('admin.lists.*', 'admin.deliverability.*', 'admin.content.*', 'admin.reputation.*', 'admin.communications.*') ? 'lite' : 'full') }}"
        data-workspace-sync-url="{{ route('admin.sync.poll') }}"
        data-workspace-sync-stream-url="{{ route('admin.sync.stream') }}"
        @if(app()->environment('local')) data-workspace-sync-use-poll="1" @endif
        data-lead-show-base="{{ url('/portal/leads') }}"
        data-workflow-show-base="{{ url('/admin/workflows') }}"
        data-push-vapid-key-url="{{ route('admin.push.vapid') }}"
        data-push-subscribe-url="{{ route('admin.push.subscribe') }}"
        data-push-test-url="{{ route('admin.push.test') }}" @endauth>
    @include('layouts.partials.sidebar-state-boot')
    <div class="flex min-h-screen">
        @include('layouts.partials.sidebar-shell', [
            'brandTitle' => config('app.name'),
            'brandSubtitle' => 'Admin',
            'logoutRoute' => route('admin.logout'),
            'nav' => view('layouts.partials.sidebar-nav-admin'),
        ])

        <div class="app-content-shell">
            @auth
                @include('layouts.partials.topnav', [
                    'workspaceManageRoute' => auth()->user()->canAccessAdminModule('user_management')
                        ? route('admin.workspaces.index')
                        : null,
                    'logoutRoute' => route('admin.logout'),
                ])
            @endauth

            <main class="app-main">
                @include('layouts.partials.app-main')
            </main>
        </div>
    </div>
    @include('layouts.partials.toasts')
    @stack('scripts')
</body>

</html>
