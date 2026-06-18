<div class="ghl-settings ghl-inbox-settings">
    <div class="ghl-inbox-settings-header">
        <h2 class="text-lg font-bold text-zinc-900">Zoom integration</h2>
        <a href="{{ route($routePrefix.'communications.index', request()->except(['panel'])) }}" class="comm-hub-link">← Back to inbox</a>
    </div>

    <div class="comm-hub-card p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-4">
        <div>
            <div class="text-sm font-bold text-slate-800">Connection</div>
            <p class="text-sm text-slate-500 mt-1">{{ $connection['message'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route($routePrefix.'communications.zoom.refresh') }}">
                @csrf
                <button type="submit" class="comm-hub-btn comm-hub-btn-secondary">Refresh data</button>
            </form>
            <span class="comm-hub-badge {{ $connection['connected'] ? 'comm-hub-badge-success' : 'comm-hub-badge-warning' }}">
                {{ $connection['connected'] ? 'Connected' : 'Not connected' }}
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
        <div class="comm-hub-card p-6 space-y-4">
            <h3 class="comm-hub-panel-title">Account credentials</h3>
            <div class="comm-hub-field"><span class="comm-hub-label">Account ID</span><code class="comm-hub-code">{{ $settings['accountId'] ?? '—' }}</code></div>
            <div class="comm-hub-field"><span class="comm-hub-label">Client ID</span><code class="comm-hub-code">{{ $settings['clientId'] ?? '—' }}</code></div>
            <div class="comm-hub-field"><span class="comm-hub-label">Secret key</span><code class="comm-hub-code">{{ $settings['maskedSecret'] ?? '—' }}</code></div>
        </div>
        <div class="comm-hub-card p-6 space-y-4">
            <h3 class="comm-hub-panel-title">Webhook secret</h3>
            <code class="comm-hub-code block">{{ $settings['webhookSecret'] ?? 'Not configured' }}</code>
        </div>
    </div>

    <div class="comm-hub-card p-6 space-y-3 mt-6">
        <h3 class="comm-hub-panel-title">Live API check</h3>
        @if(($connectionDiagnostics['phone_available'] ?? true) === false)
            <div class="text-sm text-red-800 bg-red-50 border border-red-200 rounded-lg px-3 py-3">
                <p class="font-semibold">Blocker 1 — Zoom Phone not enabled (API error 2031)</p>
                <p class="mt-1">Calls, SMS, voicemail, and phone recordings stay empty until Zoom Phone is on in <strong>Zoom Admin → Phone System</strong> for account <code class="text-xs bg-white/80 px-1 rounded">{{ $settings['accountId'] ?? '—' }}</code>. Marketplace scopes alone do not fix error 2031.</p>
            </div>
        @endif
        @if(!empty($connectionDiagnostics['messages']))
            <ul class="space-y-2">
                @foreach($connectionDiagnostics['messages'] as $hint)
                    <li class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{{ $hint }}</li>
                @endforeach
            </ul>
        @elseif(($connectionDiagnostics['phone_available'] ?? false) === true)
            <p class="text-sm text-green-700">Phone and cloud recording APIs responded successfully.</p>
        @endif
    </div>

    <div class="comm-hub-card p-6 space-y-4 mt-6">
        <h3 class="comm-hub-panel-title">Required API scopes</h3>
        <p class="text-sm text-slate-500">Add in Zoom Marketplace, save, then click <strong>Refresh data</strong> above (or run <code class="text-xs bg-slate-100 px-1 rounded">php artisan zoom:clear-token --cache</code>).</p>
        <p class="text-sm text-amber-700">Call logs, SMS, and voicemail require <strong>Zoom Phone</strong> enabled on your Zoom account — API scopes alone are not enough.</p>
        <ul class="space-y-2">
            @foreach($settings['requiredScopes'] ?? [] as $scope => $description)
                <li class="flex flex-col sm:flex-row sm:justify-between gap-1 text-sm border-b border-slate-100 pb-2">
                    <code class="text-xs text-indigo-700 bg-indigo-50 px-2 py-1 rounded">{{ $scope }}</code>
                    <span class="text-slate-600">{{ $description }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</div>
