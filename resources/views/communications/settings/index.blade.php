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
                <div class="text-sm font-bold text-slate-800">Zoom API connection</div>
                <p class="text-sm text-slate-500 mt-1">{{ $connection['message'] }}</p>
                @if($connection['expires_at'])
                    <p class="text-xs text-slate-400 mt-1">Token expires {{ \Carbon\Carbon::parse($connection['expires_at'])->diffForHumans() }}</p>
                @endif
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
                <h2 class="comm-hub-panel-title">Account credentials</h2>
                @foreach(['Account ID' => $accountId, 'Client ID' => $clientId] as $label => $value)
                    <div class="comm-hub-field">
                        <span class="comm-hub-label">{{ $label }}</span>
                        <div class="comm-hub-copy-row">
                            <code class="comm-hub-code">{{ $value ?? '—' }}</code>
                            @if($value)
                                <button type="button" class="comm-hub-copy-btn" data-copy="{{ $value }}">Copy</button>
                            @endif
                        </div>
                    </div>
                @endforeach
                <div class="comm-hub-field">
                    <span class="comm-hub-label">Secret key</span>
                    <code class="comm-hub-code">{{ $maskedSecret }}</code>
                </div>
            </div>

            <div class="comm-hub-card p-6 space-y-4">
                <h2 class="comm-hub-panel-title">Webhook secret</h2>
                <code class="comm-hub-code block">{{ $webhookSecret ?? 'Not configured' }}</code>
            </div>
        </div>

        <div class="comm-hub-card p-6 space-y-4 mt-6">
            <h2 class="comm-hub-panel-title">Required API scopes</h2>
            <p class="text-sm text-slate-500">Add in Zoom Marketplace, save, then run <code class="text-xs bg-slate-100 px-1 rounded">php artisan zoom:clear-token --cache</code> or use Refresh data above.</p>
            <ul class="space-y-2">
                @foreach($requiredScopes as $scope => $description)
                    <li class="flex flex-col sm:flex-row sm:justify-between gap-1 text-sm border-b border-slate-100 pb-2">
                        <code class="text-xs text-indigo-700 bg-indigo-50 px-2 py-1 rounded">{{ $scope }}</code>
                        <span class="text-slate-600">{{ $description }}</span>
                    </li>
                @endforeach
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
