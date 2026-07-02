@php
    $clickToCall = $clickToCall ?? app(\App\Services\Communications\ZoomClickToCallService::class);
    $dialerUrl = filled($phone ?? null)
        ? route($routePrefix . 'communications.index', ['panel' => 'dialer', 'number' => $phone])
        : route($routePrefix . 'communications.index', ['panel' => 'dialer']);
@endphp

@if (filled($phone ?? null))
    <div class="ghl-call-actions">
        <a href="{{ $dialerUrl }}" class="comm-hub-btn ghl-call-btn"
            title="Open Morpheus dialer to place this call">Call</a>
        @if ($showDialerLink ?? false)
            <span class="text-xs text-slate-500">Uses your Morpheus extension</span>
        @endif
    </div>
@endif
