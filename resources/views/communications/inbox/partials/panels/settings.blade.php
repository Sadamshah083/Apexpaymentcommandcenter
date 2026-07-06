<div class="ghl-settings ghl-inbox-settings">
    <div class="ghl-inbox-settings-header">
        <h2 class="text-lg font-bold text-zinc-900">Morpheus CX Integration</h2>
        <a href="{{ route($routePrefix . 'communications.index', request()->except(['panel'])) }}" class="comm-hub-link">←
            Back to inbox</a>
    </div>

    <div class="comm-hub-card p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mt-4">
        <div>
            <div class="text-sm font-bold text-slate-800">Connection</div>
            <p class="text-sm text-slate-500 mt-1">{{ $connection['message'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route($routePrefix . 'communications.zoom.refresh') }}">
                @csrf
                <button type="submit" class="comm-hub-btn comm-hub-btn-secondary">Refresh data</button>
            </form>
            <span
                class="comm-hub-badge {{ $connection['connected'] ? 'comm-hub-badge-success' : 'comm-hub-badge-warning' }}">
                {{ $connection['connected'] ? 'Connected' : 'Not connected' }}
            </span>
        </div>
    </div>

    <div class="comm-hub-card p-6 space-y-4 mt-6">
        <h3 class="comm-hub-panel-title">Outbound calling (Morpheus API)</h3>
        <p class="text-sm text-slate-500">
            Click-to-call requires <code>campaign_id</code>, <code>extension</code>, and <code>destination</code>.
            Caller ID is sent as <code>caller_id_number</code> / <code>caller_id_name</code>.
            <a href="{{ $outboundCalling['api_docs_url'] ?? 'https://developers.morpheus.cx/reference/post_click-to-call' }}"
                target="_blank" rel="noopener" class="comm-hub-link">API reference →</a>
        </p>
        <div class="comm-hub-field">
            <span class="comm-hub-label">Default campaign ID</span>
            <code class="comm-hub-code">{{ $outboundCalling['campaign_id'] ?? '—' }}</code>
            @if (!empty($outboundCalling['campaign_name']))
                <span class="text-xs text-slate-500 block mt-1">{{ $outboundCalling['campaign_name'] }}</span>
            @endif
        </div>
        <div class="comm-hub-field">
            <span class="comm-hub-label">Default outbound DID (caller_id_number)</span>
            <code class="comm-hub-code">{{ $outboundCalling['default_outbound_did'] ?? '—' }}</code>
        </div>
        <div class="comm-hub-field">
            <span class="comm-hub-label">Default extension</span>
            <code class="comm-hub-code">{{ $outboundCalling['default_extension'] ?? '—' }}</code>
        </div>
    </div>

    <div class="comm-hub-card p-6 space-y-4 mt-6">
        <h3 class="comm-hub-panel-title">Morpheus CX Credentials</h3>
        <div class="comm-hub-field"><span class="comm-hub-label">Morpheus Host</span><code
                class="comm-hub-code">{{ $settings['accountId'] ?? '—' }}</code></div>
        <div class="comm-hub-field"><span class="comm-hub-label">Morpheus API Key</span><code
                class="comm-hub-code">{{ $settings['maskedSecret'] ?? '—' }}</code></div>
    </div>
</div>
