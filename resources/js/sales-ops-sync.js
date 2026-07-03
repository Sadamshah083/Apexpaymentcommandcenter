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
        smoothTextUpdate(
            document.getElementById('workspace-sync-weekly-discoveries'),
            `${weekly.discoveries.actual} / ${weekly.discoveries.target}`
        );
        smoothWidthUpdate(
            document.getElementById('workspace-sync-weekly-discoveries-bar'),
            weekly.discoveries.pct ?? 0
        );
    }

    if (weekly.qualified_meetings) {
        smoothTextUpdate(
            document.getElementById('workspace-sync-weekly-meetings'),
            `${weekly.qualified_meetings.actual} / ${weekly.qualified_meetings.target}`
        );
        smoothWidthUpdate(
            document.getElementById('workspace-sync-weekly-meetings-bar'),
            weekly.qualified_meetings.pct ?? 0
        );
    }
}

function renderLeaderboardRow(row, index, options = {}) {
    const { includeScore = false } = options;
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
            ${includeScore ? `<td class="font-bold">${row.score ?? 0}</td>` : ''}
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
    smoothTextUpdate(
        document.getElementById('workspace-sync-kpi-pending-verification'),
        overview.pending_verification ?? 0
    );
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
    smoothTextUpdate(
        document.getElementById('workspace-sync-lead-attempts'),
        `${detail.contact_attempts} contact attempt(s)`
    );
    smoothTextUpdate(document.getElementById('workspace-sync-lead-stage'), detail.stage_label);

    const activitiesEl = document.getElementById('workspace-sync-lead-activities');
    if (activitiesEl && Array.isArray(detail.activities)) {
        smoothHtmlUpdate(
            activitiesEl,
            detail.activities.length === 0
                ? '<p class="text-xs text-zinc-400 italic">No activity logged yet.</p>'
                : detail.activities.map(renderActivityItem).join('')
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
        if (leaderboardBody) {
            const period = leaderboardBody.dataset.leaderboardPeriod || 'week';
            const rows = period === 'day' ? salesOps.leaderboard_day : salesOps.leaderboard;
            if (Array.isArray(rows)) {
                const includeScore = leaderboardBody.dataset.includeScore === '1';
                smoothHtmlUpdate(
                    leaderboardBody,
                    rows.length === 0
                        ? '<tr><td colspan="9" class="text-center text-zinc-400 py-8">No activity logged yet this period.</td></tr>'
                        : rows.map((row, index) => renderLeaderboardRow(row, index, { includeScore })).join('')
                );
            }
        }

        if (salesOps.reactivation) {
            updateReactivationCandidates(salesOps.reactivation);
        }

        const sdrLoadBody = document.getElementById('workspace-sync-sdr-load-body');
        if (sdrLoadBody && Array.isArray(salesOps.sdr_load)) {
            smoothHtmlUpdate(sdrLoadBody, salesOps.sdr_load.map(renderSdrLoadRow).join(''));
        }
    }

    updateLeadDetail(data?.lead_detail);
    applyToolkitSync(data?.toolkit);
}

function listStatusClass(status) {
    const map = {
        completed: 'app-badge app-badge-success',
        verifying: 'app-badge app-badge-warning',
        processing: 'app-badge app-badge-warning',
        paused: 'app-badge app-badge-muted',
        failed: 'app-badge app-badge-danger',
        empty: 'app-badge app-badge-danger',
    };

    return map[status] || 'app-badge app-badge-muted';
}

function renderEmailListRow(list) {
    return `
        <tr data-list-id="${list.id}">
            <td>
                <div class="email-lists-name">${escapeHtml(list.name)}</div>
                <div class="email-lists-meta">${escapeHtml(list.source_file || '')}</div>
            </td>
            <td class="email-lists-cell">${escapeHtml(list.uploader || '—')}</td>
            <td class="email-lists-cell text-right"><span class="email-lists-num">${Number(list.total_count || 0).toLocaleString()}</span></td>
            <td class="text-right"><span class="email-lists-num is-success">${Number(list.valid_count || 0).toLocaleString()}</span></td>
            <td class="text-right"><span class="email-lists-num is-danger">${Number(list.invalid_count || 0).toLocaleString()}</span></td>
            <td><span class="${listStatusClass(list.status)}">${escapeHtml(list.status || '')}</span></td>
            <td class="text-right"><a href="${escapeHtml(list.show_url)}" class="app-btn app-btn-secondary app-btn-sm">Open results</a></td>
        </tr>
    `;
}

function renderEmailListsEmptyRow() {
    return `
        <tr class="email-lists-empty-row">
            <td colspan="7">
                <div class="email-lists-empty">
                    <p class="email-lists-empty-title">No verification batches yet.</p>
                    <p class="email-lists-empty-desc">Upload a CSV or TXT file with one email per line to get started.</p>
                </div>
            </td>
        </tr>
    `;
}

function deliverabilityStatusClass(status) {
    const map = {
        completed: 'app-badge app-badge-success',
        processing: 'app-badge app-badge-warning',
        pending: 'app-badge app-badge-muted',
        failed: 'app-badge app-badge-danger',
    };

    return map[status] || 'app-badge app-badge-muted';
}

function renderDeliverabilityRow(test) {
    const score = test.overall_score != null ? `${test.overall_score}/10` : '—';

    return `
        <tr data-deliverability-id="${test.id}">
            <td><a href="${escapeHtml(test.show_url)}" class="deliverability-domain-link">${escapeHtml(test.domain)}</a></td>
            <td><span class="deliverability-score">${score}</span></td>
            <td><span class="${deliverabilityStatusClass(test.status)}">${escapeHtml(test.status || '')}</span></td>
            <td class="deliverability-date">${escapeHtml(test.created_at || '')}</td>
        </tr>
    `;
}

function renderDeliverabilityEmptyRow() {
    return `
        <tr class="deliverability-empty-row">
            <td colspan="4">
                <div class="deliverability-empty">
                    <p class="deliverability-empty-title">No tests yet.</p>
                    <p class="deliverability-empty-desc">Run a domain authentication test above to get started.</p>
                </div>
            </td>
        </tr>
    `;
}

function renderReactivationRow(lead, sources, csrf) {
    const options = Object.entries(sources || {})
        .map(([value, label]) => `<option value="${escapeHtml(value)}">${escapeHtml(label)}</option>`)
        .join('');

    return `
        <tr data-lead-id="${lead.id}">
            <td class="font-bold">${escapeHtml(lead.business_name)}</td>
            <td>${escapeHtml(lead.stage_label || '')}</td>
            <td class="text-xs text-zinc-500">${escapeHtml(lead.updated_at || '')}</td>
            <td>
                <form method="POST" action="/admin/sales-ops/leads/${lead.id}/reactivate" class="flex items-center gap-2">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <select name="source" class="app-input !w-auto text-xs py-1">${options}</select>
                    <button type="submit" class="app-btn app-btn-primary app-btn-sm">Enroll</button>
                </form>
            </td>
        </tr>
    `;
}

function updateReactivationCandidates(reactivation) {
    const body = document.getElementById('workspace-sync-reactivation-body');
    if (!body || !Array.isArray(reactivation.candidates)) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const html =
        reactivation.candidates.length === 0
            ? ''
            : reactivation.candidates.map((lead) => renderReactivationRow(lead, reactivation.sources, csrf)).join('');

    if (body.dataset.syncMode === 'patch') {
        const byId = new Map(reactivation.candidates.map((lead) => [String(lead.id), lead]));
        body.querySelectorAll('tr[data-lead-id]').forEach((row) => {
            const lead = byId.get(row.dataset.leadId);
            if (!lead) {
                row.remove();
                return;
            }
            const temp = document.createElement('tbody');
            temp.innerHTML = renderReactivationRow(lead, reactivation.sources, csrf);
            const fresh = temp.querySelector('tr');
            if (fresh && fresh.outerHTML !== row.outerHTML) {
                row.replaceWith(fresh);
            }
        });
        return;
    }

    smoothHtmlUpdate(body, html);
}

export function applyToolkitSync(toolkit) {
    if (!toolkit) {
        return;
    }

    const listsBody = document.getElementById('workspace-sync-email-lists-body');
    if (listsBody && Array.isArray(toolkit.email_lists)) {
        const html = toolkit.email_lists.length === 0
            ? renderEmailListsEmptyRow()
            : toolkit.email_lists.map(renderEmailListRow).join('');
        if (listsBody.dataset.syncMode === 'patch') {
            const byId = new Map(toolkit.email_lists.map((list) => [String(list.id), list]));
            listsBody.querySelectorAll('tr[data-list-id]').forEach((row) => {
                const list = byId.get(row.dataset.listId);
                if (!list) {
                    return;
                }
                const temp = document.createElement('tbody');
                temp.innerHTML = renderEmailListRow(list);
                const fresh = temp.querySelector('tr');
                if (fresh && fresh.outerHTML !== row.outerHTML) {
                    row.replaceWith(fresh);
                }
            });
        } else {
            smoothHtmlUpdate(listsBody, html);
        }
    }

    const deliverabilityBody = document.getElementById('workspace-sync-deliverability-body');
    if (deliverabilityBody && Array.isArray(toolkit.deliverability_tests)) {
        const html = toolkit.deliverability_tests.length === 0
            ? renderDeliverabilityEmptyRow()
            : toolkit.deliverability_tests.map(renderDeliverabilityRow).join('');
        smoothHtmlUpdate(deliverabilityBody, html);
    }
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
