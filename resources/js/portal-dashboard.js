import { applyAePipelineLivePatch, applyHandoffQueueLivePatch, applyPortalLeadsLivePatch } from './workspace-sync.js';

let stopPortalDashboardPoll = null;

function formatPortalMetric(key, value) {
    if (key === 'revenue_mtd') {
        return '$'.concat(Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 0 }));
    }

    if (typeof value === 'number') {
        return Number(value).toLocaleString();
    }

    return value ?? '0';
}

function updatePortalDashboard(data) {
    const root = document.getElementById('portal-dash-widgets');
    if (!root || !data?.metrics) {
        return false;
    }

    const metrics = data.metrics;

    root.querySelectorAll('[data-portal-metric]').forEach((el) => {
        const key = el.getAttribute('data-portal-metric');
        if (!key || !(key in metrics)) {
            if (`${key}_formatted` in metrics) {
                el.textContent = metrics[`${key}_formatted`];
            }
            return;
        }

        el.textContent = formatPortalMetric(key, metrics[key]);
    });

    root.querySelectorAll('[data-portal-metric-bar]').forEach((el) => {
        const key = el.getAttribute('data-portal-metric-bar');
        if (key && key in metrics) {
            el.style.width = `${Math.min(100, Number(metrics[key] || 0))}%`;
        }
    });

    return true;
}

function buildPortalLiveUrl(contextEl) {
    const base = contextEl.dataset.portalLiveUrl;
    if (!base) {
        return null;
    }

    const params = new URLSearchParams();
    params.set('view', contextEl.dataset.portalView || '');
    params.set('page', contextEl.dataset.portalPage || '1');

    ['search', 'phase', 'setter', 'closer', 'focus', 'tier', 'status', 'member'].forEach((key) => {
        const datasetKey = `portal${key.charAt(0).toUpperCase()}${key.slice(1)}`;
        const value = contextEl.dataset[datasetKey];
        if (value) {
            params.set(key, value);
        }
    });

    return `${base}?${params.toString()}`;
}

function updateSetterTeamMetrics(rows) {
    if (!Array.isArray(rows)) {
        return;
    }

    rows.forEach((row) => {
        const card = document.querySelector(`[data-team-member-id="${row.user_id}"]`);
        if (!card) {
            return;
        }

        const activeEl = card.querySelector('[data-team-metric="active"]');
        const settledEl = card.querySelector('[data-team-metric="settled"]');
        if (activeEl) {
            activeEl.textContent = Number(row.active_leads ?? 0).toLocaleString();
        }
        if (settledEl) {
            settledEl.textContent = Number(row.settled_leads ?? 0).toLocaleString();
        }
    });
}

function updateCloserTeamMetrics(rows) {
    if (!Array.isArray(rows)) {
        return;
    }

    rows.forEach((row) => {
        const card = document.querySelector(`[data-team-member-id="${row.user_id}"]`);
        if (!card) {
            return;
        }

        const activeEl = card.querySelector('[data-team-metric="active"]');
        const soldEl = card.querySelector('[data-team-metric="sold"]');
        const closedEl = card.querySelector('[data-team-metric="closed"]');
        if (activeEl) {
            activeEl.textContent = Number(row.active_leads ?? 0).toLocaleString();
        }
        if (soldEl) {
            soldEl.textContent = Number(row.sales_made ?? 0).toLocaleString();
        }
        if (closedEl) {
            closedEl.textContent = Number(row.total_closed ?? 0).toLocaleString();
        }
    });
}

function updateUnassignedAlert(count) {
    const alert = document.getElementById('portal-unassigned-alert');
    const countEl = document.getElementById('portal-unassigned-count');
    if (countEl) {
        countEl.textContent = Number(count ?? 0).toLocaleString();
    }

    if (alert) {
        alert.classList.toggle('hidden', !(count > 0));
    }
}

function updateLeaderboard(rows) {
    const container = document.getElementById('portal-leaderboard');
    if (!container || !Array.isArray(rows)) {
        return;
    }

    const role = container.dataset.portalLeaderboardRole || 'setter';
    const dashRoute = container.dataset.dashboardRoute || '';

    const html = rows.map((row, i) => {
        const stats = role === 'closer'
            ? `${Number(row.deals_funded ?? 0)} funded · ${Number(row.discoveries ?? 0)} disc`
            : `${Number(row.calls_taken ?? row.calls ?? row.dials ?? 0)} calls · ${escapeText(row.talk_label || '0s')} · ${Number(row.meetings ?? 0)} mtgs`;
        const href = dashRoute
            ? `?focus=member&member=${encodeURIComponent(row.user_id)}`
            : '#';
        return `<a href="${href}" class="dash-breakdown-row dash-breakdown-row--leader">
            <span><span class="dash-rank">#${i + 1}</span> ${escapeText(row.name || '')}</span>
            <span class="dash-breakdown-value">${escapeText(stats)}</span>
        </a>`;
    }).join('');

    container.innerHTML = html;
}

function updateUpcomingCallbacks(items) {
    const container = document.getElementById('portal-upcoming-callbacks');
    if (!container || !Array.isArray(items)) {
        return;
    }

    const wrapper = document.getElementById('portal-upcoming-callbacks-card');

    if (items.length === 0) {
        container.innerHTML = '';
        wrapper?.classList.add('hidden');
        return;
    }

    wrapper?.classList.remove('hidden');

    container.innerHTML = items.map((item) => {
        const overdueClass = item.overdue ? 'text-red-600 font-semibold' : 'text-slate-500';
        return `<a href="/portal/leads/${item.id}" data-turbo="false" class="dash-callback-row">
            <span class="font-medium">${escapeText(item.name || '')}</span>
            <span class="${overdueClass}">${escapeText(item.when || '—')}</span>
        </a>`;
    }).join('');
}

function updateSetterLoad(rows) {
    const container = document.getElementById('portal-setter-load');
    if (!container || !Array.isArray(rows)) {
        return;
    }

    container.innerHTML = rows.map((load) => {
        const warn = load.at_capacity ? 'dash-load-card--warn' : '';
        return `<a href="?focus=member&member=${encodeURIComponent(load.user_id)}" class="dash-load-card ${warn}">
            <span class="font-medium">${escapeText(load.name || '')}</span>
            <span class="dash-load-count">${Number(load.assigned ?? 0)}/${Number(load.cap ?? 0)}</span>
        </a>`;
    }).join('');
}

function escapeText(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function applyPortalLivePayload(data) {
    updatePortalDashboard(data);

    const view = data.portal_view || document.getElementById('portal-sync-context')?.dataset.portalView;

    if (view === 'setter_team' && Array.isArray(data.team_metrics)) {
        updateSetterTeamMetrics(data.team_metrics);
    }

    if (view === 'closer_team' && Array.isArray(data.team_metrics)) {
        updateCloserTeamMetrics(data.team_metrics);
    }

    if (typeof data.unassigned_leads === 'number') {
        updateUnassignedAlert(data.unassigned_leads);
    }

    if (Array.isArray(data.leaderboard)) {
        updateLeaderboard(data.leaderboard);
    }

    if (Array.isArray(data.upcoming)) {
        updateUpcomingCallbacks(data.upcoming);
    }

    if (Array.isArray(data.setter_load)) {
        updateSetterLoad(data.setter_load);
    }

    const portalLeadsBody = document.getElementById('workspace-sync-portal-leads-body');
    if (portalLeadsBody && Array.isArray(data.leads) && data.leads.length > 0) {
        applyPortalLeadsLivePatch(portalLeadsBody, data.leads);
    }

    const handoffBody = document.getElementById('workspace-sync-handoff-queue-body');
    if (handoffBody && Array.isArray(data.leads) && view === 'handoff_queue') {
        applyHandoffQueueLivePatch(handoffBody, data.leads);

        const tableWrap = document.getElementById('portal-handoff-queue-table');
        const emptyState = document.getElementById('portal-handoff-queue-empty');
        const hasRows = handoffBody.querySelectorAll('tr[data-lead-id]').length > 0;

        if (tableWrap) {
            tableWrap.classList.toggle('hidden', !hasRows);
        }
        if (emptyState) {
            emptyState.classList.toggle('hidden', hasRows);
        }
    }

    const aePipelineBody = document.getElementById('workspace-sync-ae-pipeline-body');
    if (aePipelineBody && Array.isArray(data.leads) && view === 'ae_pipeline') {
        applyAePipelineLivePatch(aePipelineBody, data.leads);
    }

    return true;
}

export function teardownPortalDashboard() {
    stopPortalDashboardPoll?.();
    stopPortalDashboardPoll = null;
}

export function initPortalDashboard() {
    const contextEl = document.getElementById('portal-sync-context');
    const widgetsRoot = document.getElementById('portal-dash-widgets');
    const startPoll = window.startProgressPoll;

    if (!startPoll) {
        return;
    }

    teardownPortalDashboard();

    if (contextEl) {
        const liveUrl = buildPortalLiveUrl(contextEl);
        if (liveUrl) {
            stopPortalDashboardPoll = startPoll(
                liveUrl,
                (payload) => applyPortalLivePayload(payload),
                { activeMs: 20000, hiddenMs: 60000 },
            );
        }
        return;
    }

    if (!widgetsRoot) {
        return;
    }

    const url = widgetsRoot.dataset.portalMetricsUrl;
    if (!url) {
        return;
    }

    stopPortalDashboardPoll = startPoll(
        url,
        (payload) => updatePortalDashboard(payload),
        { activeMs: 20000, hiddenMs: 60000 },
    );
}
