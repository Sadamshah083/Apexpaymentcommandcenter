@if (!empty($dashboard))
    <div class="space-y-4">
        {{-- KPI row --}}
        @if (!empty($dashboard['kpis']))
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 app-stat-grid">
                @foreach ($dashboard['kpis'] as $kpi)
                    @if (!empty($kpi['href']))
                        <a href="{{ $kpi['href'] }}" class="app-card app-card-padded hover:border-zinc-400 transition">
                            <p class="app-kpi-label">{{ $kpi['label'] }}</p>
                            <p class="app-kpi-value text-2xl">{{ $kpi['value'] }}</p>
                        </a>
                    @else
                        <div class="app-card app-card-padded">
                            <p class="app-kpi-label">{{ $kpi['label'] }}</p>
                            <p class="app-kpi-value text-2xl">{{ $kpi['value'] }}</p>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @switch($dashboard['role'] ?? '')
            @case('appointment_setter')
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="lg:col-span-2 app-card app-card-padded">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="app-section-title">Today's activity</h2>
                            <a href="{{ route('portal.performance') }}" class="app-link text-sm">Full performance</a>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'decision_maker_contacts' => 'DM contacts', 'discoveries' => 'Discoveries'] as $key => $label)
                                @php $metric = $dashboard['daily'][$key]; @endphp
                                <div>
                                    <p class="text-xs font-semibold text-zinc-500 uppercase">{{ $label }}</p>
                                    <p class="text-lg font-bold text-zinc-900 mt-1">{{ $metric['actual'] }}<span
                                            class="text-sm font-semibold text-zinc-400"> / {{ $metric['target'] }}</span></p>
                                    <div class="app-progress-track mt-2">
                                        <div class="app-progress-fill" style="width: {{ $metric['pct'] }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-3">Lead tiers</h2>
                        <div class="space-y-2">
                            @php $tiers = config('sales_ops.lead_tiers', []); @endphp
                            @if ($tiers !== [])
                                @foreach ($tiers as $key => $tier)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600">{{ $tier['label'] ?? $key }}</span>
                                        <span class="font-bold">{{ $dashboard['tier_breakdown'][$key] ?? 0 }}</span>
                                    </div>
                                @endforeach
                            @else
                                @forelse ($dashboard['tier_breakdown'] as $key => $count)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600">{{ ucfirst(str_replace('_', ' ', $key ?: 'Unassigned')) }}</span>
                                        <span class="font-bold">{{ $count }}</span>
                                    </div>
                                @empty
                                    <p class="text-sm text-zinc-500">No tier data yet.</p>
                                @endforelse
                            @endif
                        </div>
                    </div>
                </div>
            @break

            @case('appointment_setter_team_lead')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-3">Team activity today</h2>
                        <div class="grid grid-cols-2 gap-4">
                            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'discoveries' => 'Discoveries', 'meetings' => 'Meetings'] as $key => $label)
                                <div>
                                    <p class="text-xs font-semibold text-zinc-500 uppercase">{{ $label }}</p>
                                    <p class="text-xl font-bold text-zinc-900">{{ $dashboard['team_activity'][$key] ?? 0 }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @if (!empty($dashboard['leaderboard']))
                        <div class="app-card app-card-padded">
                            <h2 class="app-section-title mb-3">Setter leaderboard (week)</h2>
                            <div class="space-y-2">
                                @foreach ($dashboard['leaderboard'] as $i => $row)
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-zinc-700"><span
                                                class="font-bold text-zinc-400 mr-2">#{{ $i + 1 }}</span>{{ $row['name'] }}</span>
                                        <span class="font-semibold">{{ $row['dials'] }} dials · {{ $row['meetings'] }} mtgs</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>

                @if (!empty($dashboard['setter_load']))
                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-3">Setter book load</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach ($dashboard['setter_load'] as $load)
                                <div class="flex justify-between items-center text-sm border border-zinc-100 rounded-lg px-3 py-2">
                                    <span class="font-medium text-zinc-700">{{ $load['name'] }}</span>
                                    <span class="{{ ($load['at_capacity'] ?? false) ? 'text-amber-600 font-bold' : 'text-zinc-600' }}">
                                        {{ $load['assigned'] }}/{{ $load['cap'] }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @break

            @case('closer')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-3">Pipeline by status</h2>
                        <div class="space-y-2">
                            @foreach (config('sales_ops.closer_statuses', []) as $key => $label)
                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-600">{{ $label }}</span>
                                    <span class="font-bold">{{ $dashboard['status_breakdown'][$key] ?? 0 }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-3">Weekly close target</h2>
                        @php $wc = $dashboard['weekly_closes']; @endphp
                        <p class="text-2xl font-bold text-zinc-900">{{ $wc['actual'] }}<span
                                class="text-sm font-semibold text-zinc-400"> / {{ $wc['target'] }} closes</span></p>
                        <div class="app-progress-track mt-3">
                            <div class="app-progress-fill" style="width: {{ $wc['pct'] }}%"></div>
                        </div>
                    </div>
                </div>
            @break

            @case('closers_team_lead')
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-1">Team revenue MTD</h2>
                        <p class="text-3xl font-bold text-emerald-700">${{ number_format($dashboard['revenue_mtd'], 0) }}</p>
                    </div>

                    @if (!empty($dashboard['leaderboard']))
                        <div class="app-card app-card-padded">
                            <h2 class="app-section-title mb-3">Closer leaderboard (week)</h2>
                            <div class="space-y-2">
                                @foreach ($dashboard['leaderboard'] as $i => $row)
                                    <div class="flex justify-between items-center text-sm">
                                        <span class="text-zinc-700"><span
                                                class="font-bold text-zinc-400 mr-2">#{{ $i + 1 }}</span>{{ $row['name'] }}</span>
                                        <span class="font-semibold">{{ $row['deals_funded'] }} funded · {{ $row['discoveries'] }} disc</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @break
        @endswitch

        {{-- Upcoming callbacks --}}
        @if (!empty($dashboard['upcoming']) && count($dashboard['upcoming']) > 0)
            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-3">Upcoming & overdue callbacks</h2>
                <div class="divide-y divide-zinc-100">
                    @foreach ($dashboard['upcoming'] as $item)
                        <div class="flex justify-between items-center py-2 text-sm">
                            <span class="font-medium text-zinc-800">{{ $item['name'] }}</span>
                            <span class="{{ $item['overdue'] ? 'text-red-600 font-semibold' : 'text-zinc-500' }}">
                                {{ $item['when'] ?? '—' }}
                            </span>
                        </div>
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
