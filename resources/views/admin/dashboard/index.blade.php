@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
<div class="admin-dashboard-page {{ !empty($detail) ? 'admin-dashboard-page--detail' : '' }}">
    <div class="admin-dashboard-header">
        <div>
            <h2 class="admin-dashboard-title">Command Center</h2>
            <p class="admin-dashboard-subtitle">Workspace: <span>{{ $workspace->name }}</span> · Live monitoring, team activity, and pipeline reporting.</p>
        </div>
        <div class="admin-dashboard-live-badge">
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            Live Auto-syncing
        </div>
    </div>

    @include('admin.dashboard.partials.detail-panel', ['detail' => $detail ?? null])

    @if (empty($detail))
@if (!empty($ops))
<div class="admin-dash-grid-4">
    <a href="{{ $detailService->adminDetailUrl('ops-active') }}" class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--clickable">
        <p class="admin-dash-stat-label">Active CRM Leads</p>
        <p id="ops-active-leads" class="admin-dash-stat-value">{{ $ops['overview']['total_active_leads'] ?? 0 }}</p>
        <span class="admin-dash-stat-chevron" aria-hidden="true">→</span>
    </a>
    <a href="{{ $detailService->adminDetailUrl('ops-verification') }}" class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--clickable">
        <p class="admin-dash-stat-label">Awaiting Verification</p>
        <p id="ops-pending-verification" class="admin-dash-stat-value is-warning">{{ $ops['overview']['pending_verification'] ?? 0 }}</p>
        <span class="admin-dash-stat-chevron" aria-hidden="true">→</span>
    </a>
    <a href="{{ $detailService->adminDetailUrl('ops-reactivation') }}" class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--clickable">
        <p class="admin-dash-stat-label">Reactivation Queue</p>
        <p id="ops-reactivation" class="admin-dash-stat-value">{{ $ops['overview']['reactivation_queue'] ?? 0 }}</p>
        <span class="admin-dash-stat-chevron" aria-hidden="true">→</span>
    </a>
    <a href="{{ $detailService->adminDetailUrl('ops-handoff') }}" class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--clickable">
        <p class="admin-dash-stat-label">Handoff Queue</p>
        <p id="ops-handoff-queue" class="admin-dash-stat-value is-accent">{{ $ops['handoff_queue'] ?? 0 }}</p>
        <p class="admin-dash-stat-note">Settled, awaiting closer</p>
        <span class="admin-dash-stat-chevron" aria-hidden="true">→</span>
    </a>
</div>

<div class="admin-dash-grid-2">
    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Today's team activity</h3>
        <div class="admin-dash-activity-grid">
            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'discoveries' => 'Discoveries', 'meetings' => 'Meetings booked'] as $key => $label)
                <a href="{{ $detailService->adminDetailUrl('activity', ['type' => $key]) }}" class="admin-dash-activity-item admin-dash-activity-item--clickable">
                    <p class="admin-dash-stat-label">{{ $label }}</p>
                    <p id="ops-today-{{ $key }}" class="admin-dash-stat-value">{{ $ops['today_activity'][$key] ?? 0 }}</p>
                </a>
            @endforeach
        </div>
        @if (($ops['at_capacity_setters'] ?? 0) > 0)
            <p class="admin-dash-alert">{{ $ops['at_capacity_setters'] }} setter(s) at book capacity — <a href="{{ route('admin.sales-ops.distribution') }}">view load</a></p>
        @endif
    </div>

    <div class="admin-dash-card">
        <div class="admin-dash-section-head">
            <h3 class="admin-dash-section-title">Weekly leaderboard</h3>
            <a href="{{ route('admin.sales-ops.performance') }}" class="admin-dash-section-link">Full report</a>
        </div>
        <div id="ops-leaderboard" class="admin-dash-leaderboard">
            @forelse ($ops['leaderboard'] ?? [] as $i => $row)
                <a href="{{ $detailService->adminDetailUrl('performer', ['user_id' => $row['user_id']]) }}"
                    class="admin-dash-leaderboard-row admin-dash-leaderboard-row--clickable"
                    title="Open call details for {{ $row['name'] }}">
                    <span>
                        <span class="admin-dash-leaderboard-rank">#{{ $i + 1 }}</span>
                        <span class="admin-dash-leaderboard-name">{{ $row['name'] }}</span>
                        <span class="admin-dash-leaderboard-role">· {{ $row['role'] }}</span>
                    </span>
                    <span class="admin-dash-leaderboard-stats">
                        {{ (int) ($row['calls_taken'] ?? $row['calls'] ?? $row['dials'] ?? 0) }} calls
                        · {{ $row['talk_label'] ?? '0s' }} talk
                        · {{ (int) ($row['meetings'] ?? 0) }} mtgs
                        · {{ (int) ($row['deals_funded'] ?? 0) }} funded
                    </span>
                </a>
            @empty
                <p class="admin-dash-empty">No activity logged this week yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endif

<div class="admin-dash-grid-2">
    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Pipeline (all-time)</h3>
        <div class="admin-dash-rows">
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'total_leads']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Total Leads</span>
                <span id="stat-total_leads" class="admin-dash-row-value">{{ $pipeline['total_leads'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'new']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">New</span>
                <span id="stat-new" class="admin-dash-row-value">{{ $pipeline['new'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'qualified']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Qualified</span>
                <span id="stat-qualified" class="admin-dash-row-value">{{ $pipeline['qualified'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'booked']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Booked</span>
                <span id="stat-booked" class="admin-dash-row-value">{{ $pipeline['booked'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'showed']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Showed</span>
                <span id="stat-showed" class="admin-dash-row-value">{{ $pipeline['showed'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'closed_won']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Closed (Won)</span>
                <span id="stat-closed_won" class="admin-dash-row-value is-success">{{ $pipeline['closed_won'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'not_now']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Not Now</span>
                <span id="stat-not_now" class="admin-dash-row-value">{{ $pipeline['not_now'] }}</span>
            </a>
            <a href="{{ $detailService->adminDetailUrl('pipeline', ['metric' => 'dead']) }}" class="admin-dash-row admin-dash-row--clickable">
                <span class="admin-dash-row-label">Dead</span>
                <span id="stat-dead" class="admin-dash-row-value is-danger">{{ $pipeline['dead'] }}</span>
            </a>
        </div>
    </div>

    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Conversion rates</h3>
        <div class="admin-dash-rows">
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Book → Show Rate</span>
                <span id="stat-book_to_show" class="admin-dash-row-value">{{ $conversion_rates['book_to_show_rate'] !== null ? $conversion_rates['book_to_show_rate'].'%' : '-' }}</span>
            </div>
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Show → Close Rate</span>
                <span id="stat-show_to_close" class="admin-dash-row-value">{{ $conversion_rates['show_to_close_rate'] !== null ? $conversion_rates['show_to_close_rate'].'%' : '-' }}</span>
            </div>
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Overall Close Rate</span>
                <span id="stat-overall_close" class="admin-dash-row-value is-success">{{ $conversion_rates['overall_close_rate'] !== null ? $conversion_rates['overall_close_rate'].'%' : '-' }}</span>
            </div>
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Avg Closed Deal Volume</span>
                <span id="stat-avg_deal_volume" class="admin-dash-row-value">${{ number_format($conversion_rates['avg_closed_volume'], 2) }}</span>
            </div>
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Total Team Dials (period)</span>
                <span id="stat-total_dials" class="admin-dash-row-value">{{ $conversion_rates['total_dials'] }}</span>
            </div>
            <div class="admin-dash-row">
                <span class="admin-dash-row-label">Total Team Closes (period)</span>
                <span id="stat-total_closes" class="admin-dash-row-value">{{ $conversion_rates['total_closes'] }}</span>
            </div>
        </div>
    </div>
</div>

<div class="admin-dash-grid-2">
    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Leads by fronter (setters)</h3>
        <div class="admin-dash-table-wrap">
            <table class="admin-dash-table">
                <thead>
                    <tr>
                        <th>Fronter (Setter)</th>
                        <th class="text-right">Leads Logged</th>
                    </tr>
                </thead>
                <tbody id="setters-table-body">
                    @forelse ($setters as $setter)
                        <tr class="is-clickable" onclick="window.location='{{ $detailService->adminDetailUrl('user', ['user_id' => $setter['id']]) }}'">
                            <td>
                                <a href="{{ $detailService->adminDetailUrl('user', ['user_id' => $setter['id']]) }}" class="admin-dash-table-link">{{ $setter['name'] }}</a>
                            </td>
                            <td class="text-right"><span class="admin-dash-table-num">{{ $setter['leads_logged'] }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="admin-dash-empty">No active setters found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Leads by closer</h3>
        <div class="admin-dash-table-wrap">
            <table class="admin-dash-table">
                <thead>
                    <tr>
                        <th>Closer</th>
                        <th class="text-right">Deals Closed</th>
                    </tr>
                </thead>
                <tbody id="closers-table-body">
                    @forelse ($closers as $closer)
                        <tr class="is-clickable" onclick="window.location='{{ $detailService->adminDetailUrl('user', ['user_id' => $closer['id']]) }}'">
                            <td>
                                <a href="{{ $detailService->adminDetailUrl('user', ['user_id' => $closer['id']]) }}" class="admin-dash-table-link">{{ $closer['name'] }}</a>
                            </td>
                            <td class="text-right"><span class="admin-dash-table-num is-success">{{ $closer['deals_closed'] }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="admin-dash-empty">No active closers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="admin-dash-grid-charts">
    <div class="admin-dash-card">
        <h3 class="admin-dash-section-title">Pipeline conversion funnel</h3>
        <div class="admin-dash-chart-wrap">
            <canvas id="pipelineFunnelChart"></canvas>
        </div>
    </div>
</div>

@endif
</div>
@endsection

@push('scripts')
@if (empty($detail))
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const funnelEl = document.getElementById('pipelineFunnelChart');
        if (!funnelEl) return;

        const funnelCtx = funnelEl.getContext('2d');
        const detailBase = @json(url('/admin/dashboard'));

        const funnelChart = new Chart(funnelCtx, {
            type: 'bar',
            data: {
                labels: ['Total Leads', 'New', 'Qualified', 'Booked', 'Showed', 'Closed (Won)'],
                datasets: [{
                    label: 'Leads',
                    data: [
                        {{ $pipeline['total_leads'] }},
                        {{ $pipeline['new'] }},
                        {{ $pipeline['qualified'] }},
                        {{ $pipeline['booked'] }},
                        {{ $pipeline['showed'] }},
                        {{ $pipeline['closed_won'] }}
                    ],
                    backgroundColor: [
                        'rgba(100, 116, 139, 0.75)',
                        'rgba(100, 116, 139, 0.65)',
                        'rgba(100, 116, 139, 0.55)',
                        'rgba(100, 116, 139, 0.45)',
                        'rgba(100, 116, 139, 0.35)',
                        'rgba(4, 120, 87, 0.75)'
                    ],
                    borderRadius: 4,
                    borderWidth: 0,
                    barPercentage: 0.6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { color: '#f1f5f9' },
                        ticks: { precision: 0, color: '#94a3b8', font: { size: 10 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#64748b', font: { size: 10 } }
                    }
                }
            }
        });

        const pollEndpoint = "{{ route('admin.dashboard.realtime-data') }}" + window.location.search;
        let stopDashboardPoll = null;

        function escapeDashboardHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function updateOpsLeaderboard(rows) {
            const container = document.getElementById('ops-leaderboard');
            if (!container) {
                return;
            }

            if (!rows.length) {
                container.innerHTML = '<p class="admin-dash-empty">No activity logged this week yet.</p>';
                return;
            }

            container.innerHTML = rows.map((row, index) => `
                <a href="${escapeDashboardHtml(row.detail_url || '#')}"
                    class="admin-dash-leaderboard-row admin-dash-leaderboard-row--clickable"
                    title="Open call details for ${escapeDashboardHtml(row.name)}">
                    <span>
                        <span class="admin-dash-leaderboard-rank">#${index + 1}</span>
                        <span class="admin-dash-leaderboard-name">${escapeDashboardHtml(row.name)}</span>
                        <span class="admin-dash-leaderboard-role">· ${escapeDashboardHtml(row.role)}</span>
                    </span>
                    <span class="admin-dash-leaderboard-stats">${row.calls_taken ?? row.calls ?? row.dials ?? 0} calls · ${escapeDashboardHtml(row.talk_label || '0s')} talk · ${row.meetings ?? 0} mtgs · ${row.deals_funded ?? 0} funded</span>
                </a>
            `).join('');
        }

        function updateCampaignCards(campaigns) {
            const grid = document.getElementById('dashboard-campaigns-grid');
            if (!grid) {
                return;
            }

            grid.innerHTML = campaigns.map((campaign) => `
                <a href="${escapeDashboardHtml(campaign.show_url)}" class="campaign-card">
                    <div class="campaign-card-head">
                        <span class="campaign-card-name">${escapeDashboardHtml(campaign.name)}</span>
                        <span class="campaign-card-count">${Number(campaign.leads_count || 0).toLocaleString()} leads</span>
                    </div>
                    <dl class="campaign-card-stats">
                        <div><dt>Imports</dt><dd>${campaign.imports_count ?? 0}</dd></div>
                        <div><dt>Enriched</dt><dd>${Number(campaign.enriched_count || 0).toLocaleString()}</dd></div>
                        <div><dt>Assigned</dt><dd>${Number(campaign.assigned_count || 0).toLocaleString()}</dd></div>
                    </dl>
                </a>
            `).join('');
        }

        function updateDashboardMetrics(data) {
            const setText = (id, value) => {
                const el = document.getElementById(id);
                if (el) {
                    el.innerText = value;
                }
            };

            setText('stat-total_leads', data.pipeline.total_leads);
            setText('stat-new', data.pipeline.new);
            setText('stat-qualified', data.pipeline.qualified);
            setText('stat-booked', data.pipeline.booked);
            setText('stat-showed', data.pipeline.showed);
            setText('stat-closed_won', data.pipeline.closed_won);
            setText('stat-not_now', data.pipeline.not_now);
            setText('stat-dead', data.pipeline.dead);

            setText('stat-book_to_show', data.conversion_rates.book_to_show_rate !== null ? data.conversion_rates.book_to_show_rate + '%' : '-');
            setText('stat-show_to_close', data.conversion_rates.show_to_close_rate !== null ? data.conversion_rates.show_to_close_rate + '%' : '-');
            setText('stat-overall_close', data.conversion_rates.overall_close_rate !== null ? data.conversion_rates.overall_close_rate + '%' : '-');
            setText('stat-avg_deal_volume', '$' + data.conversion_rates.avg_closed_volume.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            setText('stat-total_dials', data.conversion_rates.total_dials);
            setText('stat-total_closes', data.conversion_rates.total_closes);

            if (data.ops) {
                setText('ops-active-leads', data.ops.overview?.total_active_leads ?? 0);
                setText('ops-pending-verification', data.ops.overview?.pending_verification ?? 0);
                setText('ops-reactivation', data.ops.overview?.reactivation_queue ?? 0);
                setText('ops-handoff-queue', data.ops.handoff_queue ?? 0);
                setText('ops-today-dials', data.ops.today_activity?.dials ?? 0);
                setText('ops-today-conversations', data.ops.today_activity?.conversations ?? 0);
                setText('ops-today-discoveries', data.ops.today_activity?.discoveries ?? 0);
                setText('ops-today-meetings', data.ops.today_activity?.meetings ?? 0);
                updateOpsLeaderboard(data.ops.leaderboard || []);
            }

            if (Array.isArray(data.campaigns)) {
                updateCampaignCards(data.campaigns);
            }

            if (Array.isArray(data.imports_leads) && window.applyCommandCenterLeadsPatch) {
                const importsBody = document.getElementById('workspace-sync-leads-body');
                if (importsBody) {
                    window.applyCommandCenterLeadsPatch(importsBody, data.imports_leads);
                }
            }

            if (!funnelChart) {
                return;
            }

            funnelChart.data.datasets[0].data = [
                data.pipeline.total_leads,
                data.pipeline.new,
                data.pipeline.qualified,
                data.pipeline.booked,
                data.pipeline.showed,
                data.pipeline.closed_won
            ];
            funnelChart.update();

            const settersBody = document.getElementById('setters-table-body');
            if (settersBody) {
                let settersHTML = '';
                if (data.setters.length > 0) {
                    data.setters.forEach(setter => {
                        settersHTML += `
                            <tr class="is-clickable" onclick="window.location='${detailBase}?detail=user&user_id=${setter.id}'">
                                <td><a href="${detailBase}?detail=user&user_id=${setter.id}" class="admin-dash-table-link">${setter.name}</a></td>
                                <td class="text-right"><span class="admin-dash-table-num">${setter.leads_logged}</span></td>
                            </tr>
                        `;
                    });
                } else {
                    settersHTML = `<tr><td colspan="2" class="admin-dash-empty">No active setters found.</td></tr>`;
                }
                settersBody.innerHTML = settersHTML;
            }

            const closersBody = document.getElementById('closers-table-body');
            if (closersBody) {
                let closersHTML = '';
                if (data.closers.length > 0) {
                    data.closers.forEach(closer => {
                        closersHTML += `
                            <tr class="is-clickable" onclick="window.location='${detailBase}?detail=user&user_id=${closer.id}'">
                                <td><a href="${detailBase}?detail=user&user_id=${closer.id}" class="admin-dash-table-link">${closer.name}</a></td>
                                <td class="text-right"><span class="admin-dash-table-num is-success">${closer.deals_closed}</span></td>
                            </tr>
                        `;
                    });
                } else {
                    closersHTML = `<tr><td colspan="2" class="admin-dash-empty">No active closers found.</td></tr>`;
                }
                closersBody.innerHTML = closersHTML;
            }

            const workflowsBody = document.getElementById('workflows-table-body');
            if (workflowsBody) {
                let workflowsHTML = '';
                const newLabels = [];
                const newTotals = [];
                const newEnriched = [];
                const newClosed = [];

                if (data.workflows.length > 0) {
                    data.workflows.forEach((wf, index) => {
                        if (index < 5) {
                            newLabels.push(wf.name);
                            newTotals.push(wf.total_leads);
                            newEnriched.push(wf.enriched_leads);
                            newClosed.push(wf.closed_deals);
                        }

                        workflowsHTML += `
                            <tr>
                                <td>
                                    <a href="${detailBase}?detail=workflow&workflow_id=${wf.id}" class="admin-dash-table-link">${wf.name}</a>
                                    <span class="admin-dash-table-meta">${wf.filename || ''}</span>
                                </td>
                                <td>${wf.created_at}</td>
                                <td class="text-right"><span class="admin-dash-table-num">${wf.total_leads}</span></td>
                                <td class="text-right"><span class="admin-dash-table-num is-success">${wf.enriched_leads}</span></td>
                                <td class="text-right"><span class="admin-dash-table-num is-danger">${wf.failed_leads}</span></td>
                                <td class="text-right"><span class="admin-dash-table-num is-success">${wf.closed_deals}</span></td>
                                <td class="text-right"><span class="admin-dash-badge is-success">${wf.enrichment_rate}%</span></td>
                                <td class="text-right"><span class="admin-dash-badge is-success">${wf.close_rate}%</span></td>
                                <td class="text-right">
                                    <a href="${detailBase}?detail=workflow&workflow_id=${wf.id}" class="app-btn app-btn-secondary app-btn-sm">View details</a>
                                </td>
                            </tr>
                        `;
                    });
                } else {
                    workflowsHTML = `<tr><td colspan="9" class="admin-dash-empty">No workflow files uploaded yet.</td></tr>`;
                }
                workflowsBody.innerHTML = workflowsHTML;

                if (workflowsChart) {
                    workflowsChart.data.labels = newLabels;
                    workflowsChart.data.datasets[0].data = newTotals;
                    workflowsChart.data.datasets[1].data = newEnriched;
                    workflowsChart.data.datasets[2].data = newClosed;
                    workflowsChart.update();
                }
            }
        }

        if (window.startProgressPoll) {
            stopDashboardPoll = window.startProgressPoll(pollEndpoint, (data) => {
                updateDashboardMetrics(data);
                return true;
            }, { activeMs: 5000, hiddenMs: 15000 });
        }

        document.addEventListener('turbo:before-cache', () => {
            stopDashboardPoll?.();
            stopDashboardPoll = null;
        }, { once: true });
    });
</script>
@else
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const pollEndpoint = "{{ route('admin.dashboard.realtime-data') }}" + window.location.search;
        if (window.startProgressPoll && window.updateAdminDetailPanel) {
            window.startProgressPoll(pollEndpoint, (data) => {
                window.updateAdminDetailPanel(data.detail);
                return true;
            }, { activeMs: 5000, hiddenMs: 15000 });
        }
    });
</script>
@endif
@endpush
