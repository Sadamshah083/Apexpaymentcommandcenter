<aside class="ghl-inbox-tools">
    <div class="ghl-inbox-tools-inner ghl-tools-dialer">
        @include('communications.partials.webphone-panel', [
            'routePrefix' => $routePrefix,
            'defaultCallerId' => $defaultCallerId ?? null,
        ])

        <section class="ghl-tools-section">
            <h3 class="ghl-inbox-rail-title">Quick dial</h3>
            @include('communications.inbox.partials.rail-dialer-compact', [
                'routePrefix' => $routePrefix,
                'phoneUsers' => $phoneUsers ?? [],
                'morpheusExtensions' => $morpheusExtensions ?? [],
                'defaultCallerId' => $defaultCallerId ?? null,
                'prefillNumber' => $prefillNumber ?? null,
            ])
        </section>

        @if (!empty($callStats))
            <section class="ghl-tools-section">
                <h3 class="ghl-inbox-rail-title">Call summary</h3>
                <div class="ghl-inbox-rail-stats">
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['total'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Total</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['inbound'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Inbound</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['outbound'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Outbound</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['missed'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Missed</span></div>
                </div>
            </section>
        @endif

        @if (!empty($phoneUsers))
            <section class="ghl-tools-section">
                <h3 class="ghl-inbox-rail-title">Phone lines</h3>
                <div class="ghl-team-list ghl-team-list-compact">
                    @foreach ($phoneUsers as $user)
                        @foreach ($user['phone_numbers'] as $number)
                            <div class="ghl-team-row ghl-team-row-compact">
                                <span class="text-xs font-semibold text-zinc-800 truncate">{{ $user['name'] }}</span>
                                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'dialer', 'number' => $number])) }}"
                                    class="comm-hub-link text-xs">{{ $number }}</a>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endif

        <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'dialer'])) }}"
            class="comm-hub-btn comm-hub-btn-secondary w-full text-center comm-hub-btn-sm">Full dialer</a>
    </div>
</aside>
