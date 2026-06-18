<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-cream-100 text-warmgrey-900 min-h-screen font-sans antialiased"
    @auth
        data-workspace-id="{{ auth()->user()->current_workspace_id }}"
        data-workspace-sync-url="{{ route('admin.sync.poll') }}"
        data-lead-show-base="{{ url('/portal/leads') }}"
        data-workflow-show-base="{{ url('/admin/workflows') }}"
        data-push-vapid-key-url="{{ route('admin.push.vapid') }}"
        data-push-subscribe-url="{{ route('admin.push.subscribe') }}"
        data-push-test-url="{{ route('admin.push.test') }}"
    @endauth>
    <div class="flex min-h-screen">
        @include('layouts.partials.sidebar-shell', [
            'brandTitle' => config('app.name'),
            'brandSubtitle' => 'Admin',
            'nav' => view('layouts.partials.sidebar-nav-admin'),
        ])

        <div class="app-content-shell">
            @auth
                @include('layouts.partials.topnav', [
                    'workspaceManageRoute' => route('admin.workspaces.index'),
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
