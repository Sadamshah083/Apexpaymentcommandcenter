@if (($campaigns ?? collect())->isNotEmpty())
<div id="campaigns" class="admin-dash-section {{ ($activeSection ?? '') === 'campaigns' ? 'admin-dash-section--focus' : '' }}">
    <div class="admin-dash-card">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div>
                <h3 class="admin-dash-section-title">Campaigns</h3>
                <p class="admin-dash-section-desc">Group imports and run batch enrich or assign by campaign.</p>
            </div>
            <a href="{{ route('admin.campaigns.index') }}" class="app-btn app-btn-secondary app-btn-sm">Manage campaigns</a>
        </div>
        <div id="dashboard-campaigns-grid" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach ($campaigns->take(6) as $campaign)
                <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaign-card">
                    <div class="campaign-card-head">
                        <span class="campaign-card-name">{{ $campaign->name }}</span>
                        <span class="campaign-card-count">{{ number_format($campaign->leads_count) }} leads</span>
                    </div>
                    <dl class="campaign-card-stats">
                        <div><dt>Imports</dt><dd>{{ $campaign->imports_count }}</dd></div>
                        <div><dt>Enriched</dt><dd>{{ number_format($campaign->enriched_count) }}</dd></div>
                        <div><dt>Assigned</dt><dd>{{ number_format($campaign->assigned_count) }}</dd></div>
                    </dl>
                </a>
            @endforeach
        </div>
    </div>
</div>
@endif
