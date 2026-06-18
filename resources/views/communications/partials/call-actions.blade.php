@php
    $clickToCall = $clickToCall ?? app(\App\Services\Communications\ZoomClickToCallService::class);
    $callerId = $callerId ?? config('integrations.communications.default_caller_id');
    $dialUrl = filled($phone ?? null) ? $clickToCall->dialUrl((string) $phone, $callerId) : null;
    $dialerUrl = filled($phone ?? null)
        ? route($routePrefix.'communications.index', ['mode' => 'dialer', 'number' => $phone])
        : null;
@endphp

@if($dialUrl)
    <div class="ghl-call-actions">
        <a href="{{ $dialUrl }}" class="comm-hub-btn ghl-call-btn" data-zoom-call="1" title="Open Zoom Phone to place this call">Call</a>
        @if($dialerUrl && ($showDialerLink ?? false))
            <a href="{{ $dialerUrl }}" class="comm-hub-link text-xs">Dialer</a>
        @endif
    </div>
@endif
