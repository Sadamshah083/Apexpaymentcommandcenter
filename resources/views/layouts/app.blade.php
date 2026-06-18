<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-cream-100 text-warmgrey-900 min-h-screen font-sans antialiased">
    <div class="flex min-h-screen">
        <aside class="w-64 bg-[#f3f4f6] text-warmgrey-900 border-r border-warmgrey-200 flex-shrink-0 flex flex-col justify-between h-screen sticky top-0 overflow-hidden">
            <div>
                <div class="p-6 border-b border-warmgrey-200">
                    <h1 class="text-lg font-extrabold tracking-tight text-warmgrey-900">{{ config('app.name') }}</h1>
                    <p class="text-xs text-warmgrey-500 mt-1">Marketing Deliverability</p>
                    @if(Auth::check() && Auth::user()->currentWorkspace)
                        <div class="mt-3 px-3 py-1.5 rounded-lg bg-warmgrey-200/50 border border-warmgrey-200 text-xs flex justify-between items-center">
                            <span class="truncate font-semibold text-warmgrey-900">{{ Auth::user()->currentWorkspace->name }}</span>
                            <a href="{{ route('workspaces.index') }}" class="text-[10px] text-warmgrey-500 hover:text-warmgrey-900 font-bold ml-2 transition-colors">Change</a>
                        </div>
                    @endif
                </div>
                <nav class="p-4 space-y-6">
                    <!-- Core B2B System -->
                    <div>
                        <span class="block px-3 text-[10px] font-bold uppercase tracking-wider text-warmgrey-900 mb-2">Operations</span>
                        <div class="space-y-1">
                            <a href="{{ route('dashboard') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('dashboard') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Workspace Overview</a>
                            <a href="{{ route('workflows.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('workflows.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">AI Agent Pipelines</a>
                        </div>
                    </div>

                    <!-- Workspace Validator Suite -->
                    <div>
                        <span class="block px-3 text-[10px] font-bold uppercase tracking-wider text-warmgrey-900 mb-2">Validator Toolkit</span>
                        <div class="space-y-1">
                            <a href="{{ route('lists.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('lists.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Bulk Email Verifier</a>
                            <a href="{{ route('deliverability.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('deliverability.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Domain Deliverability Scan</a>
                            <a href="{{ route('content.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('content.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Outbound Spam Analyzer</a>
                            <a href="{{ route('reputation.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('reputation.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Sender Reputation Center</a>
                        </div>
                    </div>

                    <!-- Settings & Collaborators -->
                    <div>
                        <span class="block px-3 text-[10px] font-bold uppercase tracking-wider text-warmgrey-900 mb-2">Workspace Admin</span>
                        <div class="space-y-1">
                            <a href="{{ route('workspaces.index') }}" class="block px-3 py-2 rounded-lg text-sm transition-all {{ request()->routeIs('workspaces.*') ? 'bg-warmgrey-200 text-warmgrey-900 font-semibold border border-warmgrey-200' : 'text-warmgrey-900 hover:bg-warmgrey-200' }}">Collaborators & Contexts</a>
                        </div>
                    </div>
                </nav>
            </div>
            
            <div class="p-6 mt-auto text-xs text-warmgrey-500 border-t border-warmgrey-200 space-y-4">
                @if(Auth::check())
                    <div class="space-y-1">
                        <span class="block text-[10px] text-warmgrey-500 uppercase tracking-wider font-bold">User</span>
                        <span class="block text-warmgrey-900 font-semibold truncate">{{ Auth::user()->name }}</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full py-2 bg-rose-600/15 hover:bg-rose-600 border border-rose-500/20 text-rose-600 hover:text-white font-bold rounded-lg text-center transition-all">Logout</button>
                    </form>
                @endif
                <div class="text-[10px] text-warmgrey-500/60 leading-relaxed">
                    Queue: database driver<br>
                    Run: php artisan queue:work
                </div>
            </div>
        </aside>

        <main class="flex-1 p-8 overflow-auto">
            @yield('content')
        </main>
    </div>
    @include('layouts.partials.toasts')
    @stack('scripts')
</body>
</html>
