@if (($campaigns ?? collect())->isNotEmpty())
<div id="campaigns" class="admin-dash-section {{ ($activeSection ?? '') === 'campaigns' ? 'admin-dash-section--focus' : '' }}">
    <div class="admin-dash-card campaigns-panel-card">
        <div class="campaigns-panel-head">
            <h3 class="admin-dash-section-title">Campaigns</h3>
            <a href="{{ route('admin.campaigns.index') }}" class="app-btn app-btn-secondary app-btn-sm">Manage campaigns</a>
        </div>
        <div class="campaigns-card-grid" id="dashboard-campaigns-grid">
            @foreach ($campaigns->take(12) as $campaign)
                @php
                    $kpi = ($campaignKpis[$campaign->id] ?? null) ?: ['dials' => 0, 'connected' => 0, 'connect_rate' => 0];
                    $connectRate = number_format((float) ($kpi['connect_rate'] ?? 0), 1);
                @endphp
                <article class="campaign-stat-card" data-campaign-id="{{ $campaign->id }}">
                    <div class="campaign-stat-card__header">
                        <h4 class="campaign-stat-card__name">
                            <a href="{{ route('admin.campaigns.show', $campaign) }}">{{ $campaign->name }}</a>
                        </h4>
                        <a href="{{ route('admin.campaigns.show', $campaign) }}" class="app-btn app-btn-secondary app-btn-sm">View</a>
                    </div>
                    <dl class="campaign-stat-card__stats">
                        <div>
                            <dt>Leads</dt>
                            <dd data-stat="leads">{{ number_format($campaign->leads_count) }}</dd>
                        </div>
                        <div>
                            <dt>Imports</dt>
                            <dd data-stat="imports">{{ number_format($campaign->imports_count) }}</dd>
                        </div>
                        <div>
                            <dt>Enriched</dt>
                            <dd data-stat="enriched">{{ number_format($campaign->enriched_count) }}</dd>
                        </div>
                        <div>
                            <dt>Assigned</dt>
                            <dd data-stat="assigned">{{ number_format($campaign->assigned_count) }}</dd>
                        </div>
                        <div>
                            <dt>Calls</dt>
                            <dd data-stat="calls">{{ number_format($kpi['dials'] ?? 0) }}</dd>
                        </div>
                        <div>
                            <dt>Connected</dt>
                            <dd data-stat="connected">{{ number_format($kpi['connected'] ?? 0) }}</dd>
                        </div>
                        <div class="campaign-stat-card__rate">
                            <dt>Connect rate</dt>
                            <dd data-stat="rate">{{ $connectRate }}%</dd>
                        </div>
                    </dl>
                </article>
            @endforeach
        </div>
    </div>
</div>
@endif
