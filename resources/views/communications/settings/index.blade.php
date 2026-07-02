@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Settings')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'settings', 'routePrefix' => $routePrefix])

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="ghl-settings">
        <div class="comm-hub-card p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <div class="text-sm font-bold text-slate-800">Morpheus CX connection</div>
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

        <div class="comm-hub-card p-6 space-y-4 mt-6">
            <h2 class="comm-hub-panel-title">Morpheus CX credentials</h2>
            <div class="comm-hub-field">
                <span class="comm-hub-label">Host</span>
                <div class="comm-hub-copy-row">
                    <code class="comm-hub-code">{{ $accountId ?? '—' }}</code>
                    @if($accountId)
                        <button type="button" class="comm-hub-copy-btn" data-copy="{{ $accountId }}">Copy</button>
                    @endif
                </div>
            </div>
            <div class="comm-hub-field">
                <span class="comm-hub-label">API key</span>
                <code class="comm-hub-code">{{ $maskedSecret }}</code>
            </div>
            <p class="text-sm text-slate-500">
                Set <code class="text-xs bg-slate-100 px-1 rounded">MORPHEUS_HOST</code> and
                <code class="text-xs bg-slate-100 px-1 rounded">MORPHEUS_API_KEY</code> in your server
                <code class="text-xs bg-slate-100 px-1 rounded">.env</code>, then use Refresh data above.
            </p>
        </div>

        <div class="comm-hub-card p-6 space-y-4 mt-6">
            <h2 class="comm-hub-panel-title">Call-Control API</h2>
            <p class="text-sm text-slate-500">
                Live call control (transfer, hang up, hold, queue routing) uses the Morpheus CX Call-Control API.
                Recordings, voicemails, SMS, and team chat are not exposed by this API — those hub tabs show empty states.
            </p>
            <ul class="text-sm text-slate-600 space-y-2 list-disc pl-5">
                <li><code class="text-xs">calls:read</code> / <code class="text-xs">calls:control</code> — list, transfer, hangup, hold, park, bridge, disposition</li>
                <li><code class="text-xs">queues:*</code> — queue CRUD + waiting callers</li>
                <li><code class="text-xs">conferences:*</code> — rooms, live roster, member mute/kick</li>
                <li><code class="text-xs">leads:*</code> / <code class="text-xs">campaigns:*</code> / <code class="text-xs">lists:*</code> — dialer CRM</li>
                <li><code class="text-xs">users:*</code> / <code class="text-xs">extensions:*</code> — agents &amp; SIP lines</li>
            </ul>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            window.showToast?.('Copied', 'success');
        } catch {
            window.showToast?.('Could not copy', 'error');
        }
    });
});
</script>
@endpush
