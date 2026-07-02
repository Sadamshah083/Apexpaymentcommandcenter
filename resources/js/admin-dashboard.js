let stopDashboardPoll = null;
let funnelChart = null;
let workflowsChart = null;

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = value;
    }
}

function readDashboardConfig() {
    const root = document.getElementById('admin-dashboard-root');
    if (!root?.dataset?.dashboardConfig) {
        return null;
    }

    try {
        return JSON.parse(root.dataset.dashboardConfig);
    } catch {
        return null;
    }
}

function destroyCharts() {
    funnelChart?.destroy();
    workflowsChart?.destroy();
    funnelChart = null;
    workflowsChart = null;
}

function initCharts(config) {
    const funnelCanvas = document.getElementById('pipelineFunnelChart');
    const workflowsCanvas = document.getElementById('workflowsPerformanceChart');

    if (!funnelCanvas || !workflowsCanvas || typeof window.Chart === 'undefined') {
        return;
    }

    destroyCharts();

    const pipeline = config.pipeline;

    funnelChart = new window.Chart(funnelCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['Total Leads', 'New', 'Qualified', 'Booked', 'Showed', 'Closed (Won)'],
            datasets: [{
                label: 'Leads',
                data: [
                    pipeline.total_leads,
                    pipeline.new,
                    pipeline.qualified,
                    pipeline.booked,
                    pipeline.showed,
                    pipeline.closed_won,
                ],
                backgroundColor: [
                    'rgba(99, 102, 241, 0.85)',
                    'rgba(59, 130, 246, 0.85)',
                    'rgba(14, 165, 233, 0.85)',
                    'rgba(245, 158, 11, 0.85)',
                    'rgba(236, 72, 153, 0.85)',
                    'rgba(34, 197, 94, 0.85)',
                ],
                borderRadius: 6,
                borderWidth: 0,
                barPercentage: 0.6,
            }],
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { precision: 0 } },
                y: { grid: { display: false } },
            },
        },
    });

    const topWorkflows = config.workflows.slice(0, 5);

    workflowsChart = new window.Chart(workflowsCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: topWorkflows.map((wf) => wf.name),
            datasets: [
                {
                    label: 'Total Leads',
                    data: topWorkflows.map((wf) => wf.total_leads),
                    backgroundColor: 'rgba(165, 180, 252, 0.8)',
                    borderRadius: 4,
                },
                {
                    label: 'Enriched',
                    data: topWorkflows.map((wf) => wf.enriched_leads),
                    backgroundColor: 'rgba(99, 102, 241, 0.85)',
                    borderRadius: 4,
                },
                {
                    label: 'Closed',
                    data: topWorkflows.map((wf) => wf.closed_deals),
                    backgroundColor: 'rgba(34, 197, 94, 0.85)',
                    borderRadius: 4,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { display: false }, ticks: { precision: 0 } },
            },
        },
    });
}

function updateDashboard(data) {
    if (!document.getElementById('admin-dashboard-root')) {
        return false;
    }

    setText('stat-total_leads', data.pipeline.total_leads);
    setText('stat-new', data.pipeline.new);
    setText('stat-qualified', data.pipeline.qualified);
    setText('stat-booked', data.pipeline.booked);
    setText('stat-showed', data.pipeline.showed);
    setText('stat-closed_won', data.pipeline.closed_won);
    setText('stat-not_now', data.pipeline.not_now);
    setText('stat-dead', data.pipeline.dead);

    setText(
        'stat-book_to_show',
        data.conversion_rates.book_to_show_rate !== null ? `${data.conversion_rates.book_to_show_rate}%` : '-',
    );
    setText(
        'stat-show_to_close',
        data.conversion_rates.show_to_close_rate !== null ? `${data.conversion_rates.show_to_close_rate}%` : '-',
    );
    setText(
        'stat-overall_close',
        data.conversion_rates.overall_close_rate !== null ? `${data.conversion_rates.overall_close_rate}%` : '-',
    );
    setText(
        'stat-avg_deal_volume',
        `$${Number(data.conversion_rates.avg_closed_volume).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`,
    );
    setText('stat-total_dials', data.conversion_rates.total_dials);
    setText('stat-total_closes', data.conversion_rates.total_closes);

    if (funnelChart) {
        funnelChart.data.datasets[0].data = [
            data.pipeline.total_leads,
            data.pipeline.new,
            data.pipeline.qualified,
            data.pipeline.booked,
            data.pipeline.showed,
            data.pipeline.closed_won,
        ];
        funnelChart.update();
    }

    const settersBody = document.getElementById('setters-table-body');
    if (settersBody) {
        settersBody.innerHTML =
            data.setters.length > 0
                ? data.setters
                      .map(
                          (setter) => `
                <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='/admin/workflows?assigned_user_id=${setter.id}'">
                    <td class="py-3.5 font-medium text-slate-800">
                        <a href="/admin/workflows?assigned_user_id=${setter.id}" class="text-indigo-600 hover:text-indigo-900">${setter.name}</a>
                    </td>
                    <td class="py-3.5 text-right font-extrabold text-slate-800">${setter.leads_logged}</td>
                </tr>`,
                      )
                      .join('')
                : '<tr><td colspan="2" class="py-4 text-center text-slate-400">No active setters found.</td></tr>';
    }

    const closersBody = document.getElementById('closers-table-body');
    if (closersBody) {
        closersBody.innerHTML =
            data.closers.length > 0
                ? data.closers
                      .map(
                          (closer) => `
                <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='/admin/workflows?assigned_user_id=${closer.id}'">
                    <td class="py-3.5 font-medium text-slate-800">
                        <a href="/admin/workflows?assigned_user_id=${closer.id}" class="text-indigo-600 hover:text-indigo-900">${closer.name}</a>
                    </td>
                    <td class="py-3.5 text-right font-extrabold text-green-600">${closer.deals_closed}</td>
                </tr>`,
                      )
                      .join('')
                : '<tr><td colspan="2" class="py-4 text-center text-slate-400">No active closers found.</td></tr>';
    }

    const workflowsBody = document.getElementById('workflows-table-body');
    if (workflowsBody) {
        const newLabels = [];
        const newTotals = [];
        const newEnriched = [];
        const newClosed = [];

        workflowsBody.innerHTML =
            data.workflows.length > 0
                ? data.workflows
                      .map((wf, index) => {
                          if (index < 5) {
                              newLabels.push(wf.name);
                              newTotals.push(wf.total_leads);
                              newEnriched.push(wf.enriched_leads);
                              newClosed.push(wf.closed_deals);
                          }

                          return `
                <tr class="hover:bg-slate-50 transition">
                    <td class="py-3.5 font-semibold text-slate-800">
                        <a href="/admin/workflows/${wf.id}" class="text-indigo-600 hover:text-indigo-900">${wf.name}</a>
                        <span class="block text-xs font-normal text-slate-400">${wf.filename || ''}</span>
                    </td>
                    <td class="py-3.5 text-slate-500">${wf.created_at}</td>
                    <td class="py-3.5 text-right font-semibold text-slate-800">${wf.total_leads}</td>
                    <td class="py-3.5 text-right text-emerald-600 font-semibold">${wf.enriched_leads}</td>
                    <td class="py-3.5 text-right text-red-500 font-semibold">${wf.failed_leads}</td>
                    <td class="py-3.5 text-right text-green-600 font-semibold">${wf.closed_deals}</td>
                    <td class="py-3.5 text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">${wf.enrichment_rate}%</span>
                    </td>
                    <td class="py-3.5 text-right">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">${wf.close_rate}%</span>
                    </td>
                    <td class="py-3.5 text-right">
                        <a href="/admin/workflows/${wf.id}" class="app-btn app-btn-secondary app-btn-sm !py-1 !px-2">Track file</a>
                    </td>
                </tr>`;
                      })
                      .join('')
                : '<tr><td colspan="9" class="py-4 text-center text-slate-400">No workflow files uploaded yet.</td></tr>';

        if (workflowsChart) {
            workflowsChart.data.labels = newLabels;
            workflowsChart.data.datasets[0].data = newTotals;
            workflowsChart.data.datasets[1].data = newEnriched;
            workflowsChart.data.datasets[2].data = newClosed;
            workflowsChart.update();
        }
    }

    return true;
}

export function teardownAdminDashboard() {
    stopDashboardPoll?.();
    stopDashboardPoll = null;
    destroyCharts();
}

export function initAdminDashboard() {
    const root = document.getElementById('admin-dashboard-root');
    if (!root) {
        return;
    }

    const config = readDashboardConfig();
    if (!config) {
        return;
    }

    function start() {
        if (!document.getElementById('admin-dashboard-root')) {
            return;
        }

        teardownAdminDashboard();
        initCharts(config);

        const pollUrl = root.dataset.pollUrl;
        const startPoll = window.startProgressPoll;
        if (!pollUrl || typeof startPoll !== 'function') {
            return;
        }

        stopDashboardPoll = startPoll(
            pollUrl,
            (data) => {
                if (!document.getElementById('admin-dashboard-root')) {
                    return false;
                }
                return updateDashboard(data);
            },
            { activeMs: 5000, hiddenMs: 10000 },
        );
    }

    if (typeof window.Chart !== 'undefined') {
        start();
        return;
    }

    window.setTimeout(() => {
        if (document.getElementById('admin-dashboard-root') && typeof window.Chart !== 'undefined') {
            start();
        }
    }, 200);
}
