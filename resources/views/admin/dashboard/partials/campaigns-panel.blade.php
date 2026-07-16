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
        <div class="app-data-table campaigns-overview-table-wrap">
            <div class="app-table-wrap">
                <table class="campaigns-overview-table" id="dashboard-campaigns-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Leads</th>
                            <th>Imports</th>
                            <th>Enriched</th>
                            <th>Assigned</th>
                            <th class="text-right">Open</th>
                        </tr>
                    </thead>
                    <tbody id="dashboard-campaigns-grid">
                        @foreach ($campaigns->take(12) as $campaign)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaigns-overview-name">
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td>{{ number_format($campaign->leads_count) }}</td>
                                <td>{{ number_format($campaign->imports_count) }}</td>
                                <td>{{ number_format($campaign->enriched_count) }}</td>
                                <td class="campaigns-overview-assigned">{{ number_format($campaign->assigned_count) }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}" class="app-btn app-btn-secondary app-btn-sm">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
