const TIER_LABELS = {
    tier_1: 'Tier 1 – New Leads',
    tier_2: 'Tier 2 – Active Prospecting',
    tier_3: 'Tier 3 – Follow-Up Prospects',
    tier_4: 'Tier 4 – Long-Term Nurture',
};

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function smoothTextUpdate(el, value) {
    if (!el) {
        return;
    }

    const next = String(value ?? '');
    if (el.textContent === next) {
        return;
    }

    el.classList.add('live-sync-flash');
    el.textContent = next;
    window.setTimeout(() => el.classList.remove('live-sync-flash'), 320);
}

function smoothHtmlUpdate(el, html) {
    if (!el || el.innerHTML === html) {
        return;
    }

    el.classList.add('live-sync-updating');
    window.requestAnimationFrame(() => {
        el.innerHTML = html;
        window.requestAnimationFrame(() => {
            el.classList.remove('live-sync-updating');
            el.classList.add('live-sync-updated');
            window.setTimeout(() => el.classList.remove('live-sync-updated'), 320);
        });
    });
}

function smoothWidthUpdate(el, pct) {
    if (!el) {
        return;
    }

    const next = `${Math.min(100, Math.max(0, pct))}%`;
    if (el.style.width === next) {
        return;
    }

    el.classList.add('app-progress-fill');
    el.style.width = next;
    el.classList.add('live-sync-flash');
    window.setTimeout(() => el.classList.remove('live-sync-flash'), 320);
}

function updateDailyMetrics(daily) {
    if (!daily) {
        return;
    }

    Object.entries(daily).forEach(([key, metric]) => {
        smoothTextUpdate(document.getElementById(`workspace-sync-metric-${key}-actual`), metric.actual ?? 0);
        smoothTextUpdate(document.getElementById(`workspace-sync-metric-${key}-target`), metric.target ?? 0);
        smoothWidthUpdate(document.getElementById(`workspace-sync-metric-${key}-bar`), metric.pct ?? 0);
    });
}

function updateWeeklyMetrics(weekly) {
    if (!weekly) {
        return;
    }

    if (weekly.discoveries) {
        smoothTextUpdate(document.getElementById('workspace-sync-weekly-discoveries'), `${weekly.discoveries.actual} / ${weekly.discoveries.target}`);
        smoothWidthUpdate(document.getElementById('workspace-sync-weekly-discoveries-bar'), weekly.discoveries.pct ?? 0);
    }

    if (weekly.qualified_meetings) {
        smoothTextUpdate(document.getElementById('workspace-sync-weekly-meetings'), `${weekly.qualified_meetings.actual} / ${weekly.qualified_meetings.target}`);
        smoothWidthUpdate(document.getElementById('workspace-sync-weekly-meetings-bar'), weekly.qualified_meetings.pct ?? 0);
    }
}

function renderLeaderboardRow(row, index) {
    return `
        <tr data-member-row="${row.user_id}">
            <td class="font-bold">#${index + 1}</td>
            <td>${escapeHtml(row.name)}</td>
            <td>${escapeHtml(row.role)}</td>
            <td>${row.dials}</td>
            <td>${row.conversations}</td>
            <td>${row.discoveries}</td>
            <td>${row.meetings}</td>
            <td>${row.deals_funded}</td>
        </tr>
    `;
}

function renderSdrLoadRow(row) {
    const status = row.at_capacity
        ? '<span class="app-badge app-badge-danger">At capacity</span>'
        : '<span class="app-badge app-badge-success">Accepting leads</span>';

    return `
        <tr data-sdr-id="${row.user_id}">
            <td class="font-bold">${escapeHtml(row.name)}</td>
            <td id="workspace-sync-sdr-assigned-${row.user_id}">${row.assigned}</td>
            <td>${row.cap}</td>
            <td id="workspace-sync-sdr-available-${row.user_id}">${row.available}</td>
            <td id="workspace-sync-sdr-status-${row.user_id}">${status}</td>
        </tr>
    `;
}

function updateOverview(overview) {
    if (!overview) {
        return;
    }

    smoothTextUpdate(document.getElementById('workspace-sync-kpi-active-leads'), overview.total_active_leads ?? 0);
    smoothTextUpdate(document.getElementById('workspace-sync-kpi-pending-verification'), overview.pending_verification ?? 0);
    smoothTextUpdate(document.getElementById('workspace-sync-kpi-reactivation'), overview.reactivation_queue ?? 0);

    if (overview.tier_breakdown) {
        Object.entries(overview.tier_breakdown).forEach(([tier, count]) => {
            smoothTextUpdate(document.getElementById(`workspace-sync-tier-${tier}`), count);
        });
    }

    if (overview.stage_breakdown) {
        Object.entries(overview.stage_breakdown).forEach(([stage, count]) => {
            smoothTextUpdate(document.getElementById(`workspace-sync-stage-${stage}`), count);
        });
    }
}

function renderActivityItem(activity) {
    return `
        <div class="text-xs text-zinc-600" data-activity-id="${activity.id}">
            <span class="font-bold">${escapeHtml(activity.type_label)}</span>
            · ${escapeHtml(activity.created_at)}
            ${activity.notes ? `<div class="text-zinc-400 mt-0.5">${escapeHtml(activity.notes)}</div>` : ''}
        </div>
    `;
}

function updateLeadDetail(detail) {
    if (!detail) {
        return;
    }

    smoothTextUpdate(document.getElementById('workspace-sync-lead-tier'), detail.tier_label);
    smoothTextUpdate(document.getElementById('workspace-sync-lead-attempts'), `${detail.contact_attempts} contact attempt(s)`);
    smoothTextUpdate(document.getElementById('workspace-sync-lead-stage'), detail.stage_label);

    const activitiesEl = document.getElementById('workspace-sync-lead-activities');
    if (activitiesEl && Array.isArray(detail.activities)) {
        smoothHtmlUpdate(
            activitiesEl,
            detail.activities.length === 0
                ? '<p class="text-xs text-zinc-400 italic">No activity logged yet.</p>'
                : detail.activities.map(renderActivityItem).join(''),
        );
    }
}

export function applySalesOpsSync(data) {
    const salesOps = data?.sales_ops;
    if (salesOps) {
        updateOverview(salesOps.overview);
        updateDailyMetrics(salesOps.daily);
        updateWeeklyMetrics(salesOps.weekly);

        const leaderboardBody = document.getElementById('workspace-sync-leaderboard-body');
        if (leaderboardBody && Array.isArray(salesOps.leaderboard)) {
            smoothHtmlUpdate(
                leaderboardBody,
                salesOps.leaderboard.map((row, index) => renderLeaderboardRow(row, index)).join(''),
            );
        }

        const sdrLoadBody = document.getElementById('workspace-sync-sdr-load-body');
        if (sdrLoadBody && Array.isArray(salesOps.sdr_load)) {
            smoothHtmlUpdate(
                sdrLoadBody,
                salesOps.sdr_load.map(renderSdrLoadRow).join(''),
            );
        }
    }

    updateLeadDetail(data?.lead_detail);
}

export function initAjaxActivityForms() {
    document.querySelectorAll('form[data-ajax-activity]').forEach((form) => {
        if (form.dataset.ajaxBound === '1') {
            return;
        }
        form.dataset.ajaxBound = '1';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submitBtn = form.querySelector('[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Activity log failed');
                }

                form.reset();
                document.dispatchEvent(new CustomEvent('workspace:sync-request'));
            } catch {
                form.submit();
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            }
        });
    });
}

export { TIER_LABELS, escapeHtml as salesOpsEscapeHtml, smoothWidthUpdate };
