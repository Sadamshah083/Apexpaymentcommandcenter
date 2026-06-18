<aside class="ghl-inbox-rail">
    <div class="ghl-inbox-rail-section">
        <h3 class="ghl-inbox-rail-title">Quick dial</h3>
        @include('communications.inbox.partials.rail-dialer-compact', [
            'phoneUsers' => $phoneUsers,
            'defaultCallerId' => $defaultCallerId,
            'prefillNumber' => $prefillNumber,
        ])
    </div>

    @if(!empty($callStats))
        <div class="ghl-inbox-rail-section">
            <h3 class="ghl-inbox-rail-title">Today&apos;s calls</h3>
            <div class="ghl-inbox-rail-stats">
                <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['total'] ?? 0 }}</span><span class="ghl-inbox-rail-stat-label">Total</span></div>
                <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['missed'] ?? 0 }}</span><span class="ghl-inbox-rail-stat-label">Missed</span></div>
            </div>
        </div>
    @endif

    <div class="ghl-inbox-rail-section">
        <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['panel' => 'dialer'])) }}"
           class="comm-hub-btn comm-hub-btn-secondary w-full text-center">Open full dialer</a>
    </div>
</aside>
