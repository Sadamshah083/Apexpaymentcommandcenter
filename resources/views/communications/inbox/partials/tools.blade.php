<aside class="ghl-inbox-tools">
    <div class="ghl-comm-tools-head">
        <h3 class="ghl-comm-tools-head__title">Contact details</h3>
        <button type="button" class="ghl-comm-icon-btn" data-ghl-tools-close title="Close panel" aria-label="Close panel">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
            </svg>
        </button>
    </div>

    <div class="ghl-inbox-tools-inner ghl-tools-dialer">
        @if (!empty($callStats))
            <section class="ghl-tools-section ghl-tools-section-card">
                <h3 class="ghl-inbox-rail-title">Call summary</h3>
                <div class="ghl-inbox-rail-stats">
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['total'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Total</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['inbound'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">In</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['outbound'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Out</span></div>
                    <div><span class="ghl-inbox-rail-stat-value">{{ $callStats['missed'] ?? 0 }}</span><span
                            class="ghl-inbox-rail-stat-label">Missed</span></div>
                </div>
            </section>
        @endif

        @if (!empty($callLogs))
            <section class="ghl-tools-section ghl-tools-section-card ghl-tools-call-feed">
                <div class="ghl-dialer-center-logs-head">
                    <h3 class="ghl-inbox-rail-title mb-0">Recent calls</h3>
                    <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls']) }}"
                        class="comm-hub-link text-xs">All</a>
                </div>
                <div class="ghl-tools-call-feed-list">
                    @foreach (collect($callLogs)->take(8) as $log)
                        @php
                            $callbackPhone = match ($log['direction'] ?? '') {
                                'inbound' => $log['from_phone'] ?? null,
                                'outbound' => $log['to_phone'] ?? null,
                                default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
                            };
                        @endphp
                        <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'call', 'call' => $log['id'] ?? null])) }}"
                            class="ghl-tools-call-feed-row">
                            <span class="ghl-tools-call-feed-main">
                                <span class="ghl-tools-call-feed-dir">{{ ucfirst($log['direction'] ?? 'call') }}</span>
                                <span class="ghl-tools-call-feed-number">
                                    {{ $callbackPhone ?? ($log['to_phone'] ?? $log['from_phone'] ?? '—') }}
                                </span>
                            </span>
                            <span class="ghl-tools-call-feed-meta">
                                {{ !empty($log['start_time']) ? \Carbon\Carbon::parse($log['start_time'])->diffForHumans(short: true) : '—' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if (!empty($phoneUsers))
            <section class="ghl-tools-section ghl-tools-section-card">
                <h3 class="ghl-inbox-rail-title">Phone lines</h3>
                <div class="ghl-team-list ghl-team-list-compact">
                    @foreach ($phoneUsers as $user)
                        @foreach ($user['phone_numbers'] as $number)
                            <div class="ghl-team-row ghl-team-row-compact">
                                <span class="text-xs font-semibold text-zinc-800 truncate">{{ $user['name'] }}</span>
                                <button type="button" class="comm-hub-link text-xs" data-dial-number="{{ $number }}">
                                    {{ $number }}
                                </button>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endif

        <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'calls']) }}"
            class="ghl-comm-btn w-full text-center">Call history</a>
    </div>
</aside>
