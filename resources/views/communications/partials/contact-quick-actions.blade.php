@php
    $phone = $phone ?? ($contact['phone'] ?? null);
    $smsSession = $smsSession ?? null;
@endphp

@if (filled($phone))
    <div class="ghl-quick-actions">
        @include('communications.partials.call-actions', [
            'routePrefix' => $routePrefix,
            'phone' => $phone,
        ])
        <a href="{{ route($routePrefix . 'communications.index', array_filter(['number' => $phone])) }}"
            class="comm-hub-btn comm-hub-btn-secondary ghl-quick-btn">Dialer</a>
        @if ($smsSession && !empty($smsSession['session_id']))
            <a href="{{ route($routePrefix . 'communications.index', ['mode' => 'sms', 'session' => $smsSession['session_id']]) }}"
                class="comm-hub-btn comm-hub-btn-secondary ghl-quick-btn">Open SMS</a>
        @endif
    </div>
@endif
