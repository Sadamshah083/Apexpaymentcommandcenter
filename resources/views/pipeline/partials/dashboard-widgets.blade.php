@if (!empty($dashboard))
    @php
        $dashRoute = $dashboard['dashboard_route'] ?? 'portal.setter.dashboard';
        $kpiHref = function (array $kpi) use ($dashRoute) {
            if (! empty($kpi['href'])) {
                return $kpi['href'];
            }
            if (! empty($kpi['focus'])) {
                return route($dashRoute, ['focus' => $kpi['focus']]);
            }

            return null;
        };
        $isActiveFocus = fn (string $focus) => request('focus') === $focus;
    @endphp

    <div class="portal-dash-widgets space-y-4">
        @if (!empty($dashboard['kpis']))
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 app-stat-grid">
                @foreach ($dashboard['kpis'] as $kpi)
                    @php $href = $kpiHref($kpi); @endphp
                    @if ($href)
                        <a href="{{ $href }}"
                            class="app-card app-card-padded dash-kpi-card {{ $isActiveFocus($kpi['focus'] ?? '') ? 'dash-kpi-card--active' : '' }}">
                            <p class="app-kpi-label">{{ $kpi['label'] }}</p>
                            <p class="app-kpi-value">{{ $kpi['value'] }}</p>
                            <span class="dash-kpi-chevron" aria-hidden="true">→</span>
                        </a>
                    @else
                        <div class="app-card app-card-padded dash-kpi-card">
                            <p class="app-kpi-label">{{ $kpi['label'] }}</p>
                            <p class="app-kpi-value">{{ $kpi['value'] }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @switch($dashboard['role'] ?? '')
            @case('appointment_setter')
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="lg:col-span-2 app-card app-card-padded dash-widget-card">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="app-section-title">Today's activity</h2>
                            <a href="{{ route('portal.performance') }}" class="app-link text-sm">Full performance</a>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'decision_maker_contacts' => 'DM contacts', 'discoveries' => 'Discoveries'] as $key => $label)
                                @php $metric = $dashboard['daily'][$key]; @endphp
                                <div class="dash-metric-tile">
                                    <p class="dash-metric-label">{{ $label }}</p>
                                    <p class="dash-metric-value">{{ $metric['actual'] }}<span class="dash-metric-target"> / {{ $metric['target'] }}</span></p>
                                    <div class="app-progress-track mt-2">
                                        <div class="app-progress-fill" style="width: {{ $metric['pct'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-3">Lead tiers</h2>
                        <div class="space-y-1">
                            @php $tiers = config('sales_ops.lead_tiers', []); @endphp
                            @if ($tiers !== [])
                                @foreach ($tiers as $key => $tier)
                                    <a href="{{ route($dashRoute, ['focus' => 'tier', 'tier' => $key]) }}"
                                        class="dash-breakdown-row {{ request('focus') === 'tier' && request('tier') === $key ? 'dash-breakdown-row--active' : '' }}">
                                        <span>{{ $tier['label'] ?? $key }}</span>
                                        <span class="dash-breakdown-value">{{ $dashboard['tier_breakdown'][$key] ?? 0 }}</span>
                                    </a>
                                @endforeach
                            @else
                                @forelse ($dashboard['tier_breakdown'] as $key => $count)
                                    <a href="{{ route($dashRoute, ['focus' => 'tier', 'tier' => $key]) }}"
                                        class="dash-breakdown-row">
                                        <span>{{ ucfirst(str_replace('_', ' ', $key ?: 'Unassigned')) }}</span>
                                        <span class="dash-breakdown-value">{{ $count }}</span>
                                    </a>
                                @empty
                                    <p class="text-sm text-slate-500">No tier data yet.</p>
                                @endforelse
                            @endif
                        </div>
                    </div>
                </div>
            @break

            @case('appointment_setter_team_lead')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-3">Team activity today</h2>
                        <div class="grid grid-cols-2 gap-4">
                            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'discoveries' => 'Discoveries', 'meetings' => 'Meetings'] as $key => $label)
                                <div class="dash-metric-tile">
                                    <p class="dash-metric-label">{{ $label }}</p>
                                    <p class="dash-metric-value">{{ $dashboard['team_activity'][$key] ?? 0 }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if (!empty($dashboard['leaderboard']))
                        <div class="app-card app-card-padded dash-widget-card">
                            <h2 class="app-section-title mb-3">Setter leaderboard (week)</h2>
                            <div class="space-y-1">
                                @foreach ($dashboard['leaderboard'] as $i => $row)
                                    <a href="{{ route($dashRoute, ['focus' => 'member', 'member' => $row['user_id']]) }}"
                                        class="dash-breakdown-row dash-breakdown-row--leader">
                                        <span><span class="dash-rank">#{{ $i + 1 }}</span> {{ $row['name'] }}</span>
                                        <span class="dash-breakdown-value">{{ $row['dials'] }} dials · {{ $row['meetings'] }} mtgs</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                @if (!empty($dashboard['setter_load']))
                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-3">Setter book load</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($dashboard['setter_load'] as $load)
                                <a href="{{ route($dashRoute, ['focus' => 'member', 'member' => $load['user_id']]) }}"
                                    class="dash-load-card {{ ($load['at_capacity'] ?? false) ? 'dash-load-card--warn' : '' }}">
                                    <span class="font-medium">{{ $load['name'] }}</span>
                                    <span class="dash-load-count">{{ $load['assigned'] }}/{{ $load['cap'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @break

            @case('closer')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-3">Pipeline by status</h2>
                        <div class="space-y-1">
                            @foreach (config('sales_ops.closer_statuses', []) as $key => $label)
                                <a href="{{ route($dashRoute, ['focus' => 'status', 'status' => $key]) }}"
                                    class="dash-breakdown-row {{ request('focus') === 'status' && request('status') === $key ? 'dash-breakdown-row--active' : '' }}">
                                    <span>{{ $label }}</span>
                                    <span class="dash-breakdown-value">{{ $dashboard['status_breakdown'][$key] ?? 0 }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-3">Weekly close target</h2>
                        @php $wc = $dashboard['weekly_closes']; @endphp
                        <p class="dash-metric-value text-2xl">{{ $wc['actual'] }}<span class="dash-metric-target"> / {{ $wc['target'] }} closes</span></p>
                        <div class="app-progress-track mt-3">
                            <div class="app-progress-fill" style="width: {{ $wc['pct'] }}%"></div>
                        </div>
                    </div>
                </div>
            @break

            @case('closers_team_lead')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded dash-widget-card">
                        <h2 class="app-section-title mb-1">Team revenue MTD</h2>
                        <p class="dash-revenue-value">${{ number_format($dashboard['revenue_mtd'], 0) }}</p>
                    </div>

                    @if (!empty($dashboard['leaderboard']))
                        <div class="app-card app-card-padded dash-widget-card">
                            <h2 class="app-section-title mb-3">Closer leaderboard (week)</h2>
                            <div class="space-y-1">
                                @foreach ($dashboard['leaderboard'] as $i => $row)
                                    <a href="{{ route($dashRoute, ['focus' => 'member', 'member' => $row['user_id']]) }}"
                                        class="dash-breakdown-row dash-breakdown-row--leader">
                                        <span><span class="dash-rank">#{{ $i + 1 }}</span> {{ $row['name'] }}</span>
                                        <span class="dash-breakdown-value">{{ $row['deals_funded'] }} funded · {{ $row['discoveries'] }} disc</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @break
        @endswitch

        @if (!empty($dashboard['upcoming']) && count($dashboard['upcoming']) > 0)
            <div class="app-card app-card-padded dash-widget-card">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="app-section-title mb-0">Upcoming & overdue callbacks</h2>
                    <a href="{{ route($dashRoute, ['focus' => 'callbacks']) }}" class="app-link text-sm">View all</a>
                </div>
                <div class="divide-y divide-slate-100">
                    @foreach ($dashboard['upcoming'] as $item)
                        <a href="{{ route('portal.leads.show', $item['id']) }}" data-turbo="false"
                            class="dash-callback-row">
                            <span class="font-medium">{{ $item['name'] }}</span>
                            <span class="{{ $item['overdue'] ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                                {{ $item['when'] ?? '—' }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('portal.communications.index', ['panel' => 'dialer']) }}"
                class="app-btn app-btn-primary app-btn-sm">Open dialer</a>
            @if (($dashboard['role'] ?? '') === 'appointment_setter')
                <a href="{{ route('portal.performance') }}" class="app-btn app-btn-secondary app-btn-sm">View performance</a>
            @endif
            @if (($dashboard['role'] ?? '') === 'closers_team_lead')
                <a href="{{ route('portal.closer-team.queue') }}" class="app-btn app-btn-secondary app-btn-sm">Handoff queue</a>
            @endif
        </div>
    </div>
@endif
