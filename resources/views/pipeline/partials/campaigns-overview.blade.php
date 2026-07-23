@php
    $campaigns = $campaigns ?? collect();
    $campaignKpis = $campaignKpis ?? [];
    $role = $dashboard['role'] ?? '';
    $filterRoute = match ($role) {
        'closers_team_lead' => route('portal.closer-team.dashboard'),
        default => route('portal.setter-team.dashboard'),
    };
@endphp

@if ($campaigns->isNotEmpty())
    <div class="app-card app-card-padded dash-widget-card">
        <h2 class="app-section-title mb-3">Campaigns</h2>
        <p class="text-sm text-zinc-500 mb-4">Leads, dial outcomes, and dispositions by campaign.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            @foreach ($campaigns as $campaign)
                @php
                    $isActive = (string) request('campaign') === (string) $campaign->id;
                    $metricLabel = $role === 'closers_team_lead' ? 'Handoff' : 'Ready';
                    $metricValue = $role === 'closers_team_lead'
                        ? ($campaign->handoff_count ?? 0)
                        : ($campaign->ready_count ?? 0);
                    $kpi = $campaignKpis[$campaign->id] ?? ['dials' => 0, 'connected' => 0, 'connect_rate' => 0, 'dispositions' => []];
                    $topDisposition = $kpi['dispositions'][0]['label'] ?? null;
                @endphp
                <a href="{{ $filterRoute }}?{{ http_build_query(array_filter(['campaign' => $campaign->id, 'search' => request('search'), 'setter' => request('setter'), 'closer' => request('closer'), 'phase' => request('phase')])) }}"
                    class="campaign-card {{ $isActive ? 'ring-2 ring-indigo-500 border-indigo-200' : '' }}">
                    <div class="campaign-card-head">
                        <span class="campaign-card-name">{{ $campaign->name }}</span>
                        <span class="campaign-card-count">{{ number_format($campaign->leads_count) }} leads</span>
                    </div>
                    <dl class="campaign-card-stats">
                        <div><dt>Imports</dt><dd>{{ $campaign->imports_count }}</dd></div>
                        <div><dt>{{ $metricLabel }}</dt><dd>{{ number_format($metricValue) }}</dd></div>
                        <div>
                            <dt>{{ $role === 'closers_team_lead' ? 'With closer' : 'Active' }}</dt>
                            <dd>{{ number_format($role === 'closers_team_lead' ? ($campaign->active_closer_count ?? 0) : ($campaign->active_setter_count ?? 0)) }}</dd>
                        </div>
                        <div><dt>Calls</dt><dd>{{ number_format($kpi['dials'] ?? 0) }}</dd></div>
                        <div><dt>Connected</dt><dd>{{ number_format($kpi['connected'] ?? 0) }}</dd></div>
                        <div><dt>Connect %</dt><dd>{{ number_format($kpi['connect_rate'] ?? 0, 1) }}%</dd></div>
                    </dl>
                    @if ($topDisposition)
                        <p class="text-xs text-zinc-500 mt-2 truncate">Top disposition: {{ $topDisposition }}</p>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif
