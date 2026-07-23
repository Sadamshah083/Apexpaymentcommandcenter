@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
<div class="admin-dashboard-page {{ !empty($detail) ? 'admin-dashboard-page--detail' : '' }}">
    <div class="admin-dashboard-header">
        <div>
            <h2 class="admin-dashboard-title">Dashboard</h2>
            <p class="admin-dashboard-subtitle">Workspace: <span>{{ $workspace->name }}</span> · Live calls, team activity, and pipeline KPIs.</p>
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
@php
    $callsToday = max(0, (int) ($ops['total_calls_today'] ?? 0));
    $connectedToday = max(0, (int) ($ops['connected_today'] ?? 0));
    $dispositionedToday = max(0, (int) ($ops['dispositioned_today'] ?? 0));
    $connectRate = $callsToday > 0 ? round(($connectedToday / $callsToday) * 100, 1) : 0;
    $missedToday = max(0, $callsToday - $connectedToday);
    $pendingCalls = max(0, $callsToday - $dispositionedToday);
    $assignedLeads = (int) ($pipeline['assigned_leads'] ?? 0);
    $totalLeads = (int) ($pipeline['total_leads'] ?? 0);
    $successRate = $conversion_rates['overall_close_rate'] !== null
        ? number_format((float) $conversion_rates['overall_close_rate'], 1).'%'
        : '0.0%';
    $teamDials = (int) (($ops['today_activity']['dials'] ?? 0));
    $teamMeetings = (int) (($ops['today_activity']['meetings'] ?? 0));
@endphp

<div class="admin-dash-kpi-grid">
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Total leads</p>
        <p id="stat-total_leads" class="admin-dash-stat-value">{{ number_format($totalLeads) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Assigned leads</p>
        <p id="stat-assigned_leads" class="admin-dash-stat-value">{{ number_format($assignedLeads) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Today's calls</p>
        <p id="ops-total-calls-today" class="admin-dash-stat-value is-accent">{{ number_format($callsToday) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Connected calls</p>
        <p id="ops-connected-today" class="admin-dash-stat-value">{{ number_format($connectedToday) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Completed calls</p>
        <p id="ops-dispositioned-today" class="admin-dash-stat-value">{{ number_format($dispositionedToday) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Pending calls</p>
        <p id="ops-pending-calls" class="admin-dash-stat-value is-warning">{{ number_format($pendingCalls) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Missed calls</p>
        <p id="ops-missed-today" class="admin-dash-stat-value">{{ number_format($missedToday) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Connect rate</p>
        <p id="ops-connect-rate-today" class="admin-dash-stat-value">{{ number_format($connectRate, 1) }}%</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Success rate</p>
        <p id="stat-overall_close" class="admin-dash-stat-value is-success">{{ $successRate }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Active CRM leads</p>
        <p id="ops-active-leads" class="admin-dash-stat-value">{{ number_format((int) ($ops['overview']['total_active_leads'] ?? 0)) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Team dials today</p>
        <p id="ops-today-dials" class="admin-dash-stat-value">{{ number_format($teamDials) }}</p>
    </div>
    <div class="admin-dash-card admin-dash-stat-card admin-dash-stat-card--glass">
        <p class="admin-dash-stat-label">Meetings booked</p>
        <p id="ops-today-meetings" class="admin-dash-stat-value">{{ number_format($teamMeetings) }}</p>
    </div>
</div>

@if (!empty($ops))
<div id="team-performance" class="admin-dash-section admin-dash-section--team">
    <div class="admin-dash-section-head admin-dash-section-head--page">
        <div>
            <h3 class="admin-dash-section-title">Team performance</h3>
            <p class="admin-dash-section-desc">Live activity summary and weekly leaders.</p>
        </div>
        <a href="{{ route('admin.sales-ops.performance') }}" class="app-btn app-btn-secondary app-btn-sm">Full report</a>
    </div>
    <div class="admin-dash-grid-2">
        <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
            <h3 class="admin-dash-section-title">Today's team activity</h3>
            <div class="admin-dash-activity-grid">
                @foreach ([
                    'dials' => ['label' => 'Dials', 'tone' => 'dials'],
                    'conversations' => ['label' => 'Conversations', 'tone' => 'conversations'],
                    'discoveries' => ['label' => 'Discoveries', 'tone' => 'discoveries'],
                    'meetings' => ['label' => 'Meetings booked', 'tone' => 'meetings'],
                ] as $key => $tile)
                    <a href="{{ $detailService->adminDetailUrl('activity', ['type' => $key]) }}"
                        class="admin-dash-activity-item admin-dash-activity-item--{{ $tile['tone'] }} admin-dash-activity-item--clickable">
                        <p class="admin-dash-stat-label">{{ $tile['label'] }}</p>
                        <p id="ops-today-{{ $key }}" class="admin-dash-stat-value">{{ number_format((int) ($ops['today_activity'][$key] ?? 0)) }}</p>
                    </a>
                @endforeach
            </div>
        </div>
        <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
            <div class="admin-dash-section-head">
                <h3 class="admin-dash-section-title">Weekly leaderboard</h3>
                <a href="{{ route('admin.sales-ops.performance') }}" class="admin-dash-section-link">Full report</a>
            </div>
            <div id="ops-leaderboard" class="admin-dash-leaderboard admin-dash-leaderboard--scroll">
                @forelse ($ops['leaderboard'] ?? [] as $i => $row)
                    @php
                        $lbCalls = (int) ($row['calls_taken'] ?? $row['calls'] ?? $row['dials'] ?? 0);
                        $lbMeetings = (int) ($row['meetings'] ?? 0);
                        $lbFunded = (int) ($row['deals_funded'] ?? 0);
                        $lbTalk = (string) ($row['talk_label'] ?? '0s');
                    @endphp
                    <a href="{{ $detailService->adminDetailUrl('performer', ['user_id' => $row['user_id']]) }}"
                        class="admin-dash-leaderboard-row admin-dash-leaderboard-row--clickable">
                        <span class="admin-dash-leaderboard-who">
                            <span class="admin-dash-leaderboard-rank">#{{ $i + 1 }}</span>
                            <span class="admin-dash-leaderboard-identity">
                                <span class="admin-dash-leaderboard-name">{{ $row['name'] }}</span>
                                <span class="admin-dash-leaderboard-role">{{ $row['role'] }}</span>
                            </span>
                        </span>
                        <span class="admin-dash-leaderboard-stats">
                            <span class="admin-dash-leaderboard-stat"><strong>{{ number_format($lbCalls) }}</strong> calls</span>
                            <span class="admin-dash-leaderboard-stat"><strong>{{ $lbTalk }}</strong> talk</span>
                            <span class="admin-dash-leaderboard-stat"><strong>{{ number_format($lbMeetings) }}</strong> mtgs</span>
                            <span class="admin-dash-leaderboard-stat"><strong>{{ number_format($lbFunded) }}</strong> funded</span>
                        </span>
                    </a>
                @empty
                    <p class="admin-dash-empty">No activity logged this week yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endif

<div class="admin-dash-grid-charts admin-dash-grid-charts--analytics">
    <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
        <h3 class="admin-dash-section-title">Pipeline mix</h3>
        <div class="admin-dash-chart-wrap" data-chart-wrap="pie">
            <div class="admin-dash-chart-loading" data-chart-loading>Loading chart…</div>
            <canvas id="pipelinePieChart" aria-label="Pipeline mix pie chart"></canvas>
            <p class="admin-dash-chart-empty" data-chart-empty hidden>No data available</p>
        </div>
    </div>
    <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
        <h3 class="admin-dash-section-title">Stage volume</h3>
        <div class="admin-dash-chart-wrap" data-chart-wrap="bar">
            <div class="admin-dash-chart-loading" data-chart-loading>Loading chart…</div>
            <canvas id="pipelineBarChart" aria-label="Stage volume bar chart"></canvas>
            <p class="admin-dash-chart-empty" data-chart-empty hidden>No data available</p>
        </div>
    </div>
    <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
        <h3 class="admin-dash-section-title">Conversion trend</h3>
        <div class="admin-dash-chart-wrap" data-chart-wrap="line">
            <div class="admin-dash-chart-loading" data-chart-loading>Loading chart…</div>
            <canvas id="pipelineLineChart" aria-label="Conversion trend line chart"></canvas>
            <p class="admin-dash-chart-empty" data-chart-empty hidden>No data available</p>
        </div>
    </div>
    <div class="admin-dash-card admin-dash-card--panel admin-dash-card--glass">
        <h3 class="admin-dash-section-title">Close rate</h3>
        <div class="admin-dash-chart-wrap" data-chart-wrap="donut">
            <div class="admin-dash-chart-loading" data-chart-loading>Loading chart…</div>
            <canvas id="pipelineDonutChart" aria-label="Close rate donut chart"></canvas>
            <p class="admin-dash-chart-empty" data-chart-empty hidden>No data available</p>
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
(() => {
    const detailBase = @json(url('/admin/dashboard'));
    const stageLabels = ['New', 'Qualified', 'Booked', 'Showed', 'Closed (Won)', 'Dead'];
    let stageData = [
        {{ (int) ($pipeline['new'] ?? 0) }},
        {{ (int) ($pipeline['qualified'] ?? 0) }},
        {{ (int) ($pipeline['booked'] ?? 0) }},
        {{ (int) ($pipeline['showed'] ?? 0) }},
        {{ (int) ($pipeline['closed_won'] ?? 0) }},
        {{ (int) ($pipeline['dead'] ?? 0) }}
    ];
    const funnelLabels = ['Total', 'New', 'Qualified', 'Booked', 'Showed', 'Closed'];
    let funnelData = [
        {{ (int) ($pipeline['total_leads'] ?? 0) }},
        {{ (int) ($pipeline['new'] ?? 0) }},
        {{ (int) ($pipeline['qualified'] ?? 0) }},
        {{ (int) ($pipeline['booked'] ?? 0) }},
        {{ (int) ($pipeline['showed'] ?? 0) }},
        {{ (int) ($pipeline['closed_won'] ?? 0) }}
    ];
    let closedWon = {{ (int) ($pipeline['closed_won'] ?? 0) }};
    let totalLeads = {{ (int) ($pipeline['total_leads'] ?? 0) }};

    const charts = [];
    let themeBound = false;

    const isDark = () => document.documentElement.dataset.theme === 'dark'
        || document.documentElement.classList.contains('theme-dark');

    const chartColors = () => {
        const dark = isDark();
        return {
            text: dark ? '#cbd5e1' : '#64748b',
            grid: dark ? '#334155' : '#e2e8f0',
            muted: dark ? '#94a3b8' : '#94a3b8',
            line: dark ? '#4ade80' : '#16a34a',
            area: dark ? 'rgba(74, 222, 128, 0.18)' : 'rgba(22, 163, 74, 0.14)',
            pie: dark
                ? ['#64748b', '#38bdf8', '#a78bfa', '#fbbf24', '#34d399', '#f87171']
                : ['#94a3b8', '#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'],
            bar: dark
                ? ['#64748b', '#475569', '#38bdf8', '#a78bfa', '#fbbf24', '#4ade80']
                : ['#94a3b8', '#64748b', '#0ea5e9', '#8b5cf6', '#f59e0b', '#16a34a'],
            donut: dark ? ['#4ade80', '#334155'] : ['#16a34a', '#e2e8f0'],
        };
    };

    function setChartState(wrap, state) {
        if (!wrap) return;
        const loading = wrap.querySelector('[data-chart-loading]');
        const empty = wrap.querySelector('[data-chart-empty]');
        const canvas = wrap.querySelector('canvas');
        if (loading) loading.hidden = state !== 'loading';
        if (empty) empty.hidden = state !== 'empty';
        if (canvas) canvas.hidden = state !== 'ready';
    }

    function sum(values) {
        return values.reduce((a, b) => a + Number(b || 0), 0);
    }

    function buildCharts() {
        if (typeof Chart === 'undefined') {
            return false;
        }

        charts.forEach((c) => {
            try { c.destroy(); } catch (_) {}
        });
        charts.length = 0;
        const c = chartColors();

        const pieWrap = document.querySelector('[data-chart-wrap="pie"]');
        const barWrap = document.querySelector('[data-chart-wrap="bar"]');
        const lineWrap = document.querySelector('[data-chart-wrap="line"]');
        const donutWrap = document.querySelector('[data-chart-wrap="donut"]');

        const pieEl = document.getElementById('pipelinePieChart');
        if (pieEl && pieWrap) {
            if (sum(stageData) <= 0) {
                setChartState(pieWrap, 'empty');
            } else {
                setChartState(pieWrap, 'ready');
                charts.push(new Chart(pieEl.getContext('2d'), {
                    type: 'pie',
                    data: { labels: stageLabels, datasets: [{ data: stageData, backgroundColor: c.pie, borderWidth: 0 }] },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 450 },
                        plugins: { legend: { position: 'bottom', labels: { color: c.text, boxWidth: 10, font: { size: 10 } } } }
                    }
                }));
            }
        }

        const barEl = document.getElementById('pipelineBarChart');
        if (barEl && barWrap) {
            if (sum(funnelData) <= 0) {
                setChartState(barWrap, 'empty');
            } else {
                setChartState(barWrap, 'ready');
                charts.push(new Chart(barEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: funnelLabels,
                        datasets: [{ label: 'Leads', data: funnelData, backgroundColor: c.bar, borderRadius: 6, borderWidth: 0, barPercentage: 0.65 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 450 },
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: c.text, font: { size: 10 } } },
                            y: { grid: { color: c.grid }, ticks: { precision: 0, color: c.muted, font: { size: 10 } } }
                        }
                    }
                }));
            }
        }

        const lineEl = document.getElementById('pipelineLineChart');
        if (lineEl && lineWrap) {
            if (sum(funnelData) <= 0) {
                setChartState(lineWrap, 'empty');
            } else {
                setChartState(lineWrap, 'ready');
                charts.push(new Chart(lineEl.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: funnelLabels,
                        datasets: [{
                            label: 'Pipeline flow',
                            data: funnelData,
                            borderColor: c.line,
                            backgroundColor: c.area,
                            fill: true,
                            tension: 0.35,
                            pointRadius: 3,
                            pointBackgroundColor: c.line
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 450 },
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: c.text, font: { size: 10 } } },
                            y: { grid: { color: c.grid }, ticks: { precision: 0, color: c.muted, font: { size: 10 } } }
                        }
                    }
                }));
            }
        }

        const donutEl = document.getElementById('pipelineDonutChart');
        if (donutEl && donutWrap) {
            const remaining = Math.max(0, totalLeads - closedWon);
            if (totalLeads <= 0) {
                setChartState(donutWrap, 'empty');
            } else {
                setChartState(donutWrap, 'ready');
                charts.push(new Chart(donutEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Closed won', 'Open pipeline'],
                        datasets: [{ data: [closedWon, remaining], backgroundColor: c.donut, borderWidth: 0 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '68%',
                        animation: { duration: 450 },
                        plugins: { legend: { position: 'bottom', labels: { color: c.text, boxWidth: 10, font: { size: 10 } } } }
                    }
                }));
            }
        }

        return true;
    }

    function waitForChartJs(attempt = 0) {
        document.querySelectorAll('[data-chart-wrap]').forEach((wrap) => setChartState(wrap, 'loading'));
        if (buildCharts()) {
            return;
        }
        if (attempt > 40) {
            document.querySelectorAll('[data-chart-wrap]').forEach((wrap) => setChartState(wrap, 'empty'));
            return;
        }
        window.setTimeout(() => waitForChartJs(attempt + 1), 100);
    }

    function escapeDashboardHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateOpsLeaderboard(rows) {
        const container = document.getElementById('ops-leaderboard');
        if (!container) return;
        if (!rows.length) {
            container.innerHTML = '<p class="admin-dash-empty">No activity logged this week yet.</p>';
            return;
        }
        const fmt = (value) => Number(value || 0).toLocaleString();
        container.innerHTML = rows.map((row, index) => {
            const calls = row.calls_taken ?? row.calls ?? row.dials ?? 0;
            const meetings = row.meetings ?? 0;
            const funded = row.deals_funded ?? 0;
            const talk = row.talk_label || '0s';
            const href = row.detail_url || `${detailBase}?detail=performer&user_id=${encodeURIComponent(row.user_id || '')}`;
            return `
                <a href="${escapeDashboardHtml(href)}" class="admin-dash-leaderboard-row admin-dash-leaderboard-row--clickable">
                    <span class="admin-dash-leaderboard-who">
                        <span class="admin-dash-leaderboard-rank">#${index + 1}</span>
                        <span class="admin-dash-leaderboard-identity">
                            <span class="admin-dash-leaderboard-name">${escapeDashboardHtml(row.name)}</span>
                            <span class="admin-dash-leaderboard-role">${escapeDashboardHtml(row.role || '')}</span>
                        </span>
                    </span>
                    <span class="admin-dash-leaderboard-stats">
                        <span class="admin-dash-leaderboard-stat"><strong>${fmt(calls)}</strong> calls</span>
                        <span class="admin-dash-leaderboard-stat"><strong>${escapeDashboardHtml(talk)}</strong> talk</span>
                        <span class="admin-dash-leaderboard-stat"><strong>${fmt(meetings)}</strong> mtgs</span>
                        <span class="admin-dash-leaderboard-stat"><strong>${fmt(funded)}</strong> funded</span>
                    </span>
                </a>`;
        }).join('');
    }

    function updateDashboardMetrics(data) {
        const fmt = (value) => Number(value || 0).toLocaleString();
        const fmtRate = (value) => (value !== null && value !== undefined && value !== '')
            ? `${Number(value).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 1 })}%`
            : '0.0%';
        const setText = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.innerText = value;
        };

        if (data.pipeline) {
            setText('stat-total_leads', fmt(data.pipeline.total_leads));
            setText('stat-assigned_leads', fmt(data.pipeline.assigned_leads ?? 0));
            setText('stat-overall_close', fmtRate(data.conversion_rates?.overall_close_rate));
            stageData = [
                Number(data.pipeline.new || 0),
                Number(data.pipeline.qualified || 0),
                Number(data.pipeline.booked || 0),
                Number(data.pipeline.showed || 0),
                Number(data.pipeline.closed_won || 0),
                Number(data.pipeline.dead || 0),
            ];
            funnelData = [
                Number(data.pipeline.total_leads || 0),
                Number(data.pipeline.new || 0),
                Number(data.pipeline.qualified || 0),
                Number(data.pipeline.booked || 0),
                Number(data.pipeline.showed || 0),
                Number(data.pipeline.closed_won || 0),
            ];
            closedWon = Number(data.pipeline.closed_won || 0);
            totalLeads = Number(data.pipeline.total_leads || 0);
            buildCharts();
        }

        if (data.ops) {
            const callsToday = Number(data.ops.total_calls_today ?? 0);
            const connectedToday = Number(data.ops.connected_today ?? 0);
            const dispositionedToday = Number(data.ops.dispositioned_today ?? 0);
            setText('ops-active-leads', fmt(data.ops.overview?.total_active_leads ?? 0));
            setText('ops-total-calls-today', fmt(callsToday));
            setText('ops-connected-today', fmt(connectedToday));
            setText('ops-dispositioned-today', fmt(dispositionedToday));
            setText('ops-pending-calls', fmt(Math.max(0, callsToday - dispositionedToday)));
            setText('ops-missed-today', fmt(Math.max(0, callsToday - connectedToday)));
            const rate = callsToday > 0 ? ((connectedToday / callsToday) * 100).toFixed(1) + '%' : '0.0%';
            setText('ops-connect-rate-today', rate);
            setText('ops-today-dials', fmt(data.ops.today_activity?.dials ?? 0));
            setText('ops-today-conversations', fmt(data.ops.today_activity?.conversations ?? 0));
            setText('ops-today-discoveries', fmt(data.ops.today_activity?.discoveries ?? 0));
            setText('ops-today-meetings', fmt(data.ops.today_activity?.meetings ?? 0));
            updateOpsLeaderboard(data.ops.leaderboard || []);
        }
    }

    function bindThemeRebuild() {
        if (themeBound) return;
        themeBound = true;
        document.querySelectorAll('[data-theme-toggle], [data-comm-theme-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => setTimeout(buildCharts, 60));
        });
    }

    function startPolling() {
        if (window.__apexDashPollTimer) {
            return;
        }
        const pollEndpoint = "{{ route('admin.dashboard.realtime-data') }}" + window.location.search;
        const poll = async () => {
            try {
                const res = await fetch(pollEndpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) return;
                const data = await res.json();
                updateDashboardMetrics(data);
            } catch (_) {}
        };
        window.__apexDashPollTimer = window.setInterval(poll, 30000);
    }

    function boot() {
        if (!document.getElementById('pipelinePieChart')) {
            return;
        }
        waitForChartJs();
        bindThemeRebuild();
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
    document.addEventListener('turbo:load', boot);
    document.addEventListener('turbo:render', () => {
        if (document.getElementById('pipelinePieChart')) {
            waitForChartJs();
        }
    });
})();
</script>
@endif
@endpush
@push('scripts')
@if (!empty($detail))
<script>
(() => {
    const pollEndpoint = "{{ route('admin.dashboard.realtime-data') }}" + window.location.search;
    const boot = () => {
        if (window.startProgressPoll && window.updateAdminDetailPanel) {
            window.startProgressPoll(pollEndpoint, (data) => {
                window.updateAdminDetailPanel(data.detail);
                return true;
            }, { activeMs: 30000, hiddenMs: 60000 });
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
    document.addEventListener('turbo:load', boot);
})();
</script>
@endif
@endpush