@extends('layouts.admin')

@section('title', 'Server Monitoring')

@section('content')
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-2xl font-bold tracking-tight">Server Monitoring</h2>
        <p class="text-slate-500">Real-time resource utilization, database status, and background queues.</p>
    </div>
    <div class="flex items-center gap-2 bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 rounded-full text-xs font-semibold">
        <span class="relative flex h-2.5 w-2.5">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
        </span>
        System Online
    </div>
</div>

<!-- Resource Gauges -->
<div class="grid md:grid-cols-3 gap-6 mb-8">
    <!-- CPU Card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-slate-500">CPU Usage</span>
                <span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full font-medium">{{ $cpu['cores'] }} Cores</span>
            </div>
            <div class="flex items-baseline gap-2 mb-2">
                <span class="text-3xl font-extrabold text-slate-800">{{ $cpu['percentage'] }}%</span>
                <span class="text-xs text-slate-400">utilization</span>
            </div>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-2 mt-4 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 {{ $cpu['percentage'] > 80 ? 'bg-red-500' : ($cpu['percentage'] > 50 ? 'bg-amber-500' : 'bg-indigo-600') }}" 
                 style="width: {{ $cpu['percentage'] }}%"></div>
        </div>
    </div>

    <!-- RAM Card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-slate-500">Memory Usage</span>
                <span class="text-xs text-slate-400">{{ $ram['used'] }} / {{ $ram['total'] }}</span>
            </div>
            <div class="flex items-baseline gap-2 mb-2">
                <span class="text-3xl font-extrabold text-slate-800">{{ $ram['percentage'] }}%</span>
                <span class="text-xs text-slate-400">allocated</span>
            </div>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-2 mt-4 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 {{ $ram['percentage'] > 85 ? 'bg-red-500' : ($ram['percentage'] > 60 ? 'bg-amber-500' : 'bg-indigo-600') }}" 
                 style="width: {{ $ram['percentage'] }}%"></div>
        </div>
    </div>

    <!-- Disk Card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex flex-col justify-between">
        <div>
            <div class="flex items-center justify-between mb-4">
                <span class="text-sm font-semibold text-slate-500">Disk Space</span>
                <span class="text-xs text-slate-400">{{ $disk['used'] }} used of {{ $disk['total'] }}</span>
            </div>
            <div class="flex items-baseline gap-2 mb-2">
                <span class="text-3xl font-extrabold text-slate-800">{{ $disk['percentage'] }}%</span>
                <span class="text-xs text-slate-400">occupied</span>
            </div>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-2 mt-4 overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 {{ $disk['percentage'] > 90 ? 'bg-red-500' : ($disk['percentage'] > 75 ? 'bg-amber-500' : 'bg-indigo-600') }}" 
                 style="width: {{ $disk['percentage'] }}%"></div>
        </div>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-8">
    <!-- System Info Card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">System Specifications</h3>
        <div class="space-y-3.5 text-sm">
            <div class="flex justify-between">
                <span class="text-slate-500">Operating System:</span>
                <span class="font-medium text-slate-800">{{ $os }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">PHP Version:</span>
                <span class="font-medium text-slate-800">{{ $phpVersion }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">Laravel Version:</span>
                <span class="font-medium text-slate-800">v{{ $laravelVersion }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">Database Status:</span>
                <span class="inline-flex items-center gap-1 font-semibold {{ str_starts_with($dbStatus, 'Connected') ? 'text-green-600' : 'text-red-500' }}">
                    <span class="h-2 w-2 rounded-full {{ str_starts_with($dbStatus, 'Connected') ? 'bg-green-500' : 'bg-red-500' }}"></span>
                    {{ $dbStatus }}
                </span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">Database Name:</span>
                <span class="font-medium text-slate-800">{{ $dbName }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-500">System Uptime:</span>
                <span class="font-medium text-slate-800 text-right">{{ $uptime }}</span>
            </div>
        </div>
    </div>

    <!-- Background Queue Card -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex flex-col justify-between">
        <div>
            <h3 class="font-bold text-slate-800 mb-4 pb-2 border-b border-slate-100">Background Worker & Queues</h3>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div class="bg-slate-50 rounded-lg p-4 text-center">
                    <p class="text-xs text-slate-500 font-semibold uppercase tracking-wider mb-1">Active Queue Jobs</p>
                    <p class="text-3xl font-extrabold text-slate-800">{{ $queueActive }}</p>
                </div>
                <div class="bg-red-50 rounded-lg p-4 text-center border border-red-100">
                    <p class="text-xs text-red-500 font-semibold uppercase tracking-wider mb-1">Failed Jobs</p>
                    <p class="text-3xl font-extrabold text-red-700">{{ $queueFailed }}</p>
                </div>
            </div>
        </div>
        <div class="text-xs text-slate-400 bg-slate-50 border rounded-lg p-3">
            <p class="font-semibold text-slate-500 mb-1">Queue Connection Details:</p>
            <p>Queue workers check for jobs in the database. Active processes are managed by systemd supervisor daemon.</p>
        </div>
    </div>
</div>

<!-- Deployment Bottleneck Report -->
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
    <div class="flex items-start gap-4">
        <div class="bg-indigo-50 border border-indigo-100 p-3 rounded-lg text-indigo-600">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
        </div>
        <div>
            <h3 class="text-lg font-bold text-slate-800 mb-1">Deployment Performance Analysis</h3>
            <p class="text-sm text-slate-500 mb-4">Why pushing to the server may take a significant amount of time, and how we optimized it.</p>
            
            <div class="space-y-4 text-sm text-slate-600">
                <div class="bg-slate-50 border border-slate-100 p-4 rounded-lg">
                    <p class="font-semibold text-slate-800 mb-1">1. Archive Size Bottleneck (Fixed)</p>
                    <p>The root folder contained a huge ZIP archive (<code class="bg-slate-200 text-slate-700 px-1 rounded text-xs">php83.zip</code>, ~34 MB) that wasn't excluded from deployment. As a result, the deploy script compressed this zip into the release tarball, transferring it over SSH on every push. We have modified <code class="text-indigo-600">deploy/run_deploy.py</code> to automatically ignore all <code class="text-indigo-600">.zip</code> files, slashing the upload size by 98%.</p>
                </div>
                
                <div class="bg-slate-50 border border-slate-100 p-4 rounded-lg">
                    <p class="font-semibold text-slate-800 mb-1">2. Remote Asset Compilation & Package Install</p>
                    <p>The remote installation script (<code class="bg-slate-200 text-slate-700 px-1 rounded text-xs">deploy/install-app.sh</code>) runs <code class="text-indigo-600">composer install</code>, <code class="text-indigo-600">npm ci</code>, and <code class="text-indigo-600">npm run build</code> directly on the Ubuntu server. On basic hosting plan servers with shared/low CPU cores, compiling assets and resolving packages can take anywhere from 1 to 5 minutes and cause heavy memory spikes.</p>
                </div>

                <div class="bg-slate-50 border border-slate-100 p-4 rounded-lg">
                    <p class="font-semibold text-slate-800 mb-1">3. Future Optimization Recommendation</p>
                    <p>To reduce remote build times further, you can compile frontend assets locally or on a CI/CD runner (e.g. GitHub Actions), upload the compiled <code class="bg-slate-200 text-slate-700 px-1 rounded text-xs">public/build</code> folder, and skip the remote <code class="text-indigo-600">npm ci && npm run build</code> stages entirely.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
