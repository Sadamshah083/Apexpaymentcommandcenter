import { showToast } from './toast.js';
import { updateMemberRows } from './member-management.js';
import { isOsNotificationsEnabled, showOsNotification } from './system-notifications.js';
import { applyWorkspaceAdminState } from './workspace-admin.js';
import {
    applySalesOpsSync,
    initAjaxActivityForms,
    TIER_LABELS,
    smoothWidthUpdate,
    applyToolkitSync,
} from './sales-ops-sync.js';

const WORKFLOW_STATUS_LABELS = {
    mapping: 'Setup',
    pending: 'Queued',
    extracting: 'Enriching',
    paused: 'Paused',
    completed: 'Complete',
    failed: 'Failed',
};

/** Only these event types may surface an in-app toast. */
const NOTIFY_EVENT_TYPES = new Set([
    'workflow.completed',
    'workflow.queued',
    'workflow.paused',
    'workflow.resumed',
    'workflow.deleted',
    'member.invited',
    'member.joined',
    'member.suspended',
    'member.reactivated',
    'member.removed',
    'member.role_updated',
    'lead.verified',
    'lead.assigned',
    'lead.activity',
]);

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function workflowActionForms(workflow, showBase) {
    const pauseForm = ['pending', 'extracting'].includes(workflow.status)
        ? `<form method="POST" action="${showBase}/${workflow.id}/pause" class="inline">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="app-link text-xs text-amber-700">Pause</button>
           </form>`
        : '';
    const resumeForm =
        workflow.status === 'paused'
            ? `<form method="POST" action="${showBase}/${workflow.id}/resume" class="inline">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="app-link text-xs text-emerald-700">Resume</button>
           </form>`
            : '';
    const deleteForm = `<form method="POST" action="${showBase}/${workflow.id}" class="inline" onsubmit="return confirm('Delete this pipeline and all lead records from the database?')">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="app-link text-xs text-rose-600">Delete</button>
           </form>`;

    return `${pauseForm}${resumeForm}${deleteForm}`;
}

function stageLabel(stage) {
    return (stage || 'lead').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderLeadTagChips(lead) {
    const tags = Array.isArray(lead.tags) ? lead.tags : [];
    const tagHtml = tags.length
        ? `<div class="flex flex-wrap gap-1 mt-0.5">${tags.map((tag) => `<span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-zinc-100 text-zinc-600" style="border-left: 2px solid ${escapeHtml(tag.color || '#6366f1')}">${escapeHtml(tag.name)}</span>`).join('')}</div>`
        : '';
    const listHtml = lead.lead_list_name
        ? `<div class="text-[10px] text-zinc-400 mt-0.5">List: <span class="font-medium text-zinc-600">${escapeHtml(lead.lead_list_name)}</span></div>`
        : '';

    return `${listHtml}${tagHtml}`;
}

function renderLeadRow(lead, leadShowBase) {
    const contact =
        lead.direct_email && lead.direct_email !== 'Not Publicly Available'
            ? `<div class="text-zinc-700">${escapeHtml(lead.direct_email)}</div>`
            : '';
    const phone =
        lead.direct_phone && lead.direct_phone !== 'Not Publicly Available'
            ? `<div class="text-xs text-zinc-400 mt-0.5">${escapeHtml(lead.direct_phone)}</div>`
            : '';
    const contactFallback =
        !lead.direct_email && !lead.direct_phone
            ? '<span class="text-xs text-zinc-400 italic">None available</span>'
            : '';
    const tierLabel = lead.tier_label || TIER_LABELS[lead.tier] || '';
    const editIcon = `<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>`;

    return `
        <tr data-lead-id="${lead.id}">
            <td>
                <div class="font-bold text-zinc-900">${escapeHtml(lead.business_name)}</div>
                ${lead.address ? `<div class="text-xs text-zinc-500 mt-0.5">${escapeHtml(lead.address)}</div>` : ''}
                <div class="text-[10px] text-zinc-400 font-normal mt-0.5">${escapeHtml(lead.city)}, ${escapeHtml(lead.state)}</div>
                ${renderLeadTagChips(lead)}
            </td>
            <td class="font-medium text-zinc-600">${escapeHtml(lead.owner_name || 'Not Found')}</td>
            <td>${contact}${phone}${contactFallback}</td>
            <td>
                <span class="app-badge app-badge-info">${escapeHtml(lead.payment_processor || 'Unknown')}</span>
            </td>
            <td>
                ${tierLabel ? `<div class="text-[10px] font-semibold text-zinc-400 mb-1">${escapeHtml(tierLabel)}</div>` : ''}
                <span class="app-badge app-badge-muted">${escapeHtml(lead.stage_label || stageLabel(lead.stage))}</span>
            </td>
            <td class="text-right">
                <a href="${leadShowBase}/${lead.id}" class="app-icon-btn" title="Open lead">${editIcon}</a>
            </td>
        </tr>
    `;
}

function pipelineStatusClass(status) {
    const map = {
        completed: 'app-badge app-badge-success',
        failed: 'app-badge app-badge-danger',
        extracting: 'app-badge app-badge-info',
        pending_verification: 'app-badge app-badge-warning',
        pending: 'app-badge app-badge-muted',
    };

    return map[status] || 'app-badge app-badge-muted';
}

const WORKFLOW_PILL_CLASS = {
    mapping: 'app-status-pill-setup',
    pending: 'app-status-pill-queued',
    extracting: 'app-status-pill-enriching',
    paused: 'app-status-pill-paused',
    completed: 'app-status-pill-complete',
    failed: 'app-status-pill-failed',
};

function renderWorkflowStatusPill(status) {
    const label = WORKFLOW_STATUS_LABELS[status] || status;
    const pillClass = WORKFLOW_PILL_CLASS[status] || 'app-status-pill-queued';

    return `<span class="app-status-pill ${pillClass}">${escapeHtml(label)}</span>`;
}

const LEAD_PIPELINE_STATUS_LABELS = {
    pending_verification: 'Needs review',
    completed: 'Released',
    extracting: 'Enriching',
    failed: 'Failed',
    pending: 'Queued',
};

function renderPipelineLeadRow(lead, leadShowBase, csrf) {
    const status = LEAD_PIPELINE_STATUS_LABELS[lead.status] || (lead.status || '').replace(/_/g, ' ');
    const location = [lead.city, lead.state].filter(Boolean).join(', ');
    const failureNote =
        lead.status === 'failed' && lead.error_message
            ? `<div class="text-xs text-rose-600 mt-1 max-w-xs">${escapeHtml(lead.error_message).slice(0, 120)}</div>`
            : '';
    const actions =
        lead.status === 'pending_verification'
            ? `<div class="flex items-center justify-end gap-1">
                <form method="POST" action="/admin/leads/${lead.id}/approve">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="app-btn app-btn-success app-btn-sm">Approve</button>
                </form>
                <form method="POST" action="/admin/leads/${lead.id}/reject">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="app-btn app-btn-ghost-danger app-btn-sm">Reject</button>
                </form>
           </div>`
            : lead.status === 'completed'
              ? '<span class="text-xs font-semibold text-emerald-700">Released</span>'
              : '';

    const email =
        lead.direct_email && lead.direct_email !== 'Not Publicly Available' ? escapeHtml(lead.direct_email) : '';
    const phone =
        lead.direct_phone && lead.direct_phone !== 'Not Publicly Available' ? escapeHtml(lead.direct_phone) : '';
    const contact =
        email || phone
            ? `${email ? `<div>${email}</div>` : ''}${phone ? `<div class="text-xs text-zinc-400 mt-0.5">${phone}</div>` : ''}`
            : '<span class="text-zinc-400">—</span>';

    return `
        <tr data-lead-id="${lead.id}" data-lead-status="${escapeHtml(lead.status || '')}">
            <td>
                <a href="${leadShowBase}/${lead.id}" class="font-bold text-zinc-900 hover:underline">${escapeHtml(lead.business_name)}</a>
                ${location ? `<div class="text-xs text-zinc-400 mt-0.5">${escapeHtml(location)}</div>` : ''}
                ${renderLeadTagChips(lead)}
            </td>
            <td class="text-sm text-zinc-600">${escapeHtml(lead.owner_name || '—')}</td>
            <td class="text-sm text-zinc-600">${contact}</td>
            <td><span class="${pipelineStatusClass(lead.status)}">${escapeHtml(status)}</span>${failureNote}</td>
            <td class="text-right whitespace-nowrap">${actions}</td>
        </tr>
    `;
}

function renderWorkflowCard(workflow, showBase) {
    const openLabel = workflow.status === 'mapping' ? 'Continue setup' : 'Open';

    return `
        <div class="app-import-card" data-workflow-id="${workflow.id}">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="app-import-card-title">${escapeHtml(workflow.name)}</h3>
                    <p class="app-import-card-meta">${escapeHtml(workflow.original_filename || '')}</p>
                </div>
                ${renderWorkflowStatusPill(workflow.status)}
            </div>
                <div class="mt-3 flex items-center justify-between text-xs text-zinc-500">
                    <span>${workflow.processed_leads} / ${workflow.total_leads} processed</span>
                </div>
                ${
                    workflow.lead_list_name ||
                    (Array.isArray(workflow.import_tag_ids) && workflow.import_tag_ids.length)
                        ? `<div class="mt-2 text-xs text-zinc-500">${workflow.lead_list_name ? `List: <strong class="text-zinc-700">${escapeHtml(workflow.lead_list_name)}</strong>` : ''}${workflow.import_tag_ids?.length ? `<span class="${workflow.lead_list_name ? 'ml-2' : ''}">Tagged import</span>` : ''}</div>`
                        : ''
                }
            <div class="mt-3 flex items-center justify-end gap-3">
                <a href="${showBase}/${workflow.id}" class="app-link text-xs">${openLabel}</a>
                ${workflowActionForms(workflow, showBase)}
            </div>
        </div>
    `;
}

function renderTeam(members) {
    return members
        .map(
            (member) => `
        <div class="py-3 flex items-center justify-between" data-member-id="${member.id}">
            <div>
                <div class="font-bold text-zinc-900 text-sm">${escapeHtml(member.name)}</div>
                <div class="text-xs text-zinc-400 mt-0.5">${escapeHtml(member.email)}</div>
            </div>
            <span class="app-badge app-badge-muted">
                ${escapeHtml(member.role)}${member.status !== 'active' ? ` (${member.status})` : ''}
            </span>
        </div>
    `
        )
        .join('');
}

const AE_PIPELINE_STAGES = new Set(['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost']);

function renderAePipelineRow(lead, leadShowBase) {
    const volume = lead.monthly_processing_volume ? `$${Number(lead.monthly_processing_volume).toLocaleString()}` : '—';
    const meeting = lead.schedule_at || '—';

    return `
        <tr data-lead-id="${lead.id}">
            <td class="font-bold">${escapeHtml(lead.business_name)}</td>
            <td>${escapeHtml(lead.owner_name || '—')}</td>
            <td>${escapeHtml(lead.stage_label || stageLabel(lead.stage))}</td>
            <td>${volume}</td>
            <td>${escapeHtml(lead.current_processor || lead.payment_processor || '—')}</td>
            <td class="text-xs">${escapeHtml(meeting)}</td>
            <td><a href="${leadShowBase}/${lead.id}" class="app-link text-sm">Open</a></td>
        </tr>
    `;
}

const PIPELINE_STEP_CHECK =
    '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>';

function syncModeIsPatch(el) {
    return el?.dataset?.syncMode === 'patch';
}

function rowFromHtml(html) {
    const temp = document.createElement('tbody');
    temp.innerHTML = html.trim();
    return temp.querySelector('tr');
}

function patchTableRows(tbody, items, renderRow, renderArgs) {
    const byId = new Map(items.map((item) => [String(item.id), item]));
    tbody.querySelectorAll('tr[data-lead-id]').forEach((row) => {
        const item = byId.get(row.dataset.leadId);
        if (!item) {
            return;
        }
        const newRow = rowFromHtml(renderRow(item, ...renderArgs));
        if (newRow && newRow.outerHTML !== row.outerHTML) {
            row.replaceWith(newRow);
        }
    });
}

function syncTableBody(tbody, items, renderRow, renderArgs = []) {
    if (!tbody || !Array.isArray(items)) {
        return;
    }

    if (syncModeIsPatch(tbody)) {
        patchTableRows(tbody, items, renderRow, renderArgs);
        return;
    }

    const html = items.length === 0 ? '' : items.map((item) => renderRow(item, ...renderArgs)).join('');
    smoothHtmlUpdate(tbody, html);
}

function patchWorkflowCards(container, workflows, showBase) {
    const byId = new Map(workflows.map((wf) => [String(wf.id), wf]));
    container.querySelectorAll('[data-workflow-id]').forEach((card) => {
        const wf = byId.get(card.dataset.workflowId);
        if (!wf) {
            return;
        }
        const temp = document.createElement('div');
        temp.innerHTML = renderWorkflowCard(wf, showBase);
        const fresh = temp.firstElementChild;
        if (fresh && fresh.innerHTML !== card.innerHTML) {
            card.innerHTML = fresh.innerHTML;
        }
    });
}

function updatePipelineProgress(wf) {
    if (!wf?.pipeline_steps) {
        return;
    }

    const stepsContainer = document.querySelector('.pipeline-steps');
    if (!stepsContainer) {
        return;
    }

    wf.pipeline_steps.forEach((step, index) => {
        const detailEl = document.getElementById(`workspace-sync-step-${step.key}`);
        if (detailEl) {
            smoothTextUpdate(detailEl, step.detail);
        }

        const stepEl = detailEl?.closest('.pipeline-step');
        if (stepEl) {
            stepEl.classList.toggle('is-done', Boolean(step.done));
            stepEl.classList.toggle('is-active', Boolean(step.active));
            const dot = stepEl.querySelector('.pipeline-step-dot');
            if (dot) {
                dot.innerHTML = step.done ? PIPELINE_STEP_CHECK : `<span>${index + 1}</span>`;
            }
        }
    });

    const connectors = stepsContainer.querySelectorAll('.pipeline-steps-connector');
    wf.pipeline_steps.forEach((step, index) => {
        if (index === 0) {
            return;
        }
        const connector = connectors[index - 1];
        if (connector) {
            connector.classList.toggle('is-done', Boolean(wf.pipeline_steps[index - 1].done));
        }
    });
}

function reapplyPipelineLeadFilter() {
    const filters = document.getElementById('pipeline-lead-filters');
    const active = filters?.querySelector('button.is-active');
    const filter = active?.dataset.filter || 'all';
    document.querySelectorAll('#workspace-sync-pipeline-leads tr').forEach((row) => {
        row.hidden = filter !== 'all' && row.dataset.leadStatus !== filter;
    });
}

function formatWorkflowProgressLabel(wf) {
    const done = wf.attempted_leads ?? (wf.enriched_leads ?? 0) + (wf.failed_leads ?? 0);
    return `${wf.completion_pct ?? 0}% · ${done} / ${wf.total_leads ?? 0}`;
}

const SYNC_ACTIVE_MS = 2000;
const SYNC_HIDDEN_MS = 10000;
const SYNC_ERROR_MS = 4000;

function updateSyncIndicator(state) {
    const indicator = document.getElementById('workspace-sync-indicator');
    if (!indicator) {
        return;
    }

    indicator.classList.toggle('is-paused', state === 'paused');
    indicator.classList.toggle('is-syncing', state === 'syncing');

    const text = indicator.querySelector('.app-topnav-status-text');
    if (text) {
        const labels = {
            syncing: 'Syncing…',
            paused: 'Reconnecting',
            live: 'Live',
        };
        text.textContent = labels[state] || 'Live';
    }
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

const SYNC_EVENT_TOASTS = {
    'workflow.completed': (payload) => {
        const failed = Number(payload.failed_leads || 0);
        if (failed > 0) {
            return `Pipeline finished: ${payload.processed_leads || 0} succeeded, ${failed} failed.`;
        }

        return `Pipeline completed: ${payload.name || 'Workflow'}`;
    },
    'workflow.queued': (payload) => `Pipeline queued: ${payload.name || 'Workflow'}`,
    'workflow.paused': (payload) => `Pipeline stopped: ${payload.name || 'Workflow'}`,
    'workflow.resumed': (payload) => `Pipeline resumed: ${payload.name || 'Workflow'}`,
    'workflow.deleted': (payload) => `Pipeline deleted: ${payload.name || 'Workflow'}`,
    'member.invited': (payload) => `Invited ${payload.email || 'a user'} to the workspace.`,
    'member.joined': (payload) => `${payload.email || payload.name || 'A member'} joined the workspace.`,
    'member.suspended': (payload) => `${payload.name || payload.email || 'Member'} was suspended.`,
    'member.reactivated': (payload) => `${payload.name || payload.email || 'Member'} was reactivated.`,
    'member.removed': (payload) => `${payload.name || payload.email || 'Member'} was removed from the workspace.`,
    'member.role_updated': (payload) => `Member role updated to ${payload.role || 'new role'}.`,
    'lead.verified': (payload) => `Lead approved: ${payload.business_name || 'Lead'}`,
    'lead.assigned': (payload) => `New lead assigned: ${payload.business_name || 'Lead'}`,
    'lead.activity': (payload) => `Activity logged on ${payload.business_name || 'lead'}`,
};

const SYNC_EVENT_TITLES = {
    'workflow.completed': 'Pipeline completed',
    'workflow.queued': 'Pipeline queued',
    'workflow.paused': 'Pipeline stopped',
    'workflow.resumed': 'Pipeline resumed',
    'workflow.deleted': 'Pipeline deleted',
    'member.invited': 'Member invited',
    'member.joined': 'Member joined',
    'member.suspended': 'Member suspended',
    'member.reactivated': 'Member reactivated',
    'member.removed': 'Member removed',
    'member.role_updated': 'Role updated',
    'lead.verified': 'Lead approved',
    'lead.assigned': 'Lead assigned',
    'lead.activity': 'Activity logged',
};

function toastTypeForSyncEvent(type) {
    if (type === 'member.suspended' || type === 'member.removed') return 'warning';
    if (type === 'member.reactivated' || type === 'workflow.completed' || type === 'workflow.queued') return 'success';
    if (type.startsWith('member.')) return 'info';
    return 'info';
}

function cursorStorageKey(workspaceId) {
    return `workspace-sync-cursor-${workspaceId || 'default'}`;
}

function seenStorageKey(workspaceId) {
    return `workspace-sync-seen-${workspaceId || 'default'}`;
}

function readyStorageKey(workspaceId) {
    return `workspace-sync-ready-${workspaceId || 'default'}`;
}

function loadStoredCursor(workspaceId) {
    try {
        const value = sessionStorage.getItem(cursorStorageKey(workspaceId));
        return value !== null ? Number(value) : 0;
    } catch {
        return 0;
    }
}

function saveStoredCursor(workspaceId, cursor) {
    try {
        sessionStorage.setItem(cursorStorageKey(workspaceId), String(cursor));
    } catch {
        // Ignore storage failures.
    }
}

function loadSeenEventIds(workspaceId) {
    try {
        const raw = sessionStorage.getItem(seenStorageKey(workspaceId));
        if (!raw) return new Set();
        const parsed = JSON.parse(raw);
        return new Set(Array.isArray(parsed) ? parsed : []);
    } catch {
        return new Set();
    }
}

function saveSeenEventIds(workspaceId, ids) {
    try {
        const capped = [...ids].slice(-2000);
        sessionStorage.setItem(seenStorageKey(workspaceId), JSON.stringify(capped));
    } catch {
        // Ignore storage failures.
    }
}

function markEventsSeen(workspaceId, events, seenIds) {
    if (!Array.isArray(events)) {
        return;
    }

    events.forEach((event) => {
        if (event?.id) {
            seenIds.add(event.id);
        }
    });

    saveSeenEventIds(workspaceId, seenIds);
}

function maybeShowOsNotification(event, message, leadShowBase, workflowShowBase) {
    if (!isOsNotificationsEnabled()) {
        return;
    }

    const url =
        event.entity_type === 'workflow_lead' && event.entity_id
            ? `${leadShowBase}/${event.entity_id}`
            : workflowShowBase;

    showOsNotification({
        title: SYNC_EVENT_TITLES[event.type] || 'Workspace update',
        body: message,
        url,
    });
}

/** At most one in-app toast per sync poll. */
function notifySyncEvents(events, workspaceId, seenIds, leadShowBase, workflowShowBase) {
    if (!Array.isArray(events) || events.length === 0) {
        return;
    }

    const unseen = events.filter((event) => event?.id && !seenIds.has(event.id));
    if (unseen.length === 0) {
        return;
    }

    markEventsSeen(workspaceId, unseen, seenIds);

    const toastable = unseen.filter((event) => NOTIFY_EVENT_TYPES.has(event.type));
    if (toastable.length === 0) {
        return;
    }

    const event = toastable[toastable.length - 1];
    const formatter = SYNC_EVENT_TOASTS[event.type];
    if (!formatter) {
        return;
    }

    const message = formatter(event.payload || {});
    if (!message) {
        return;
    }

    showToast(message, toastTypeForSyncEvent(event.type));
    maybeShowOsNotification(event, message, leadShowBase, workflowShowBase);
}

let syncTimer = null;
let syncInflight = null;
let syncVisibilityHandler = null;
let syncRequestHandler = null;

export function teardownWorkspaceSync() {
    if (syncTimer) {
        window.clearTimeout(syncTimer);
        syncTimer = null;
    }
    syncInflight?.abort();
    syncInflight = null;
    if (syncVisibilityHandler) {
        document.removeEventListener('visibilitychange', syncVisibilityHandler);
        syncVisibilityHandler = null;
    }
    if (syncRequestHandler) {
        document.removeEventListener('workspace:sync-request', syncRequestHandler);
        syncRequestHandler = null;
    }
}

export function initWorkspaceSync() {
    teardownWorkspaceSync();

    const root = document.body;
    const syncUrl = root.dataset.workspaceSyncUrl;
    if (!syncUrl) return;

    const workspaceId = root.dataset.workspaceId || 'default';
    let version = null;
    let cursor = loadStoredCursor(workspaceId);
    let hasSyncedOnce = sessionStorage.getItem(readyStorageKey(workspaceId)) === '1';
    const seenEventIds = loadSeenEventIds(workspaceId);
    const pageContext = document.getElementById('workspace-sync-page');
    const workflowId = pageContext?.dataset.workflowId || root.dataset.workspaceWorkflowId || null;
    const leadId = pageContext?.dataset.leadId || root.dataset.workspaceLeadId || null;
    const leadShowBase = root.dataset.leadShowBase || '/portal/leads';
    const workflowShowBase = root.dataset.workflowShowBase || '/admin/workflows';

    const leadsBody = document.getElementById('workspace-sync-leads-body');
    const pipelineLeadsBody = document.getElementById('workspace-sync-pipeline-leads');
    const workflowsList = document.getElementById('workspace-sync-workflows');
    const teamList = document.getElementById('workspace-sync-team');
    const workflowStatus = document.getElementById('workspace-sync-workflow-status');
    const workflowProgress = document.getElementById('workspace-sync-workflow-progress');
    const workflowAssigned = document.getElementById('workspace-sync-workflow-assigned');
    const workflowPendingReview = document.getElementById('workspace-sync-workflow-pending-review');
    const workflowPendingReview2 = document.getElementById('workspace-sync-workflow-pending-review-2');
    const workflowProgressBar = document.getElementById('workspace-sync-workflow-progress-bar');
    const workflowProgressLabel = document.getElementById('workspace-sync-workflow-progress-label');
    const aePipelineBody = document.getElementById('workspace-sync-ae-pipeline-body');

    initAjaxActivityForms();

    function onSyncRequest() {
        schedulePoll(0);
    }

    syncRequestHandler = onSyncRequest;
    document.addEventListener('workspace:sync-request', syncRequestHandler);

    function schedulePoll(ms) {
        if (syncTimer) {
            window.clearTimeout(syncTimer);
        }
        syncTimer = window.setTimeout(poll, ms);
    }

    async function poll() {
        if (syncInflight) {
            syncInflight.abort();
        }
        syncInflight = new AbortController();
        updateSyncIndicator('syncing');

        try {
            const params = new URLSearchParams();
            if (version) params.set('v', version);
            params.set('cursor', String(cursor));
            if (workflowId) params.set('workflow_id', workflowId);
            if (leadId) params.set('lead_id', leadId);

            const response = await fetch(`${syncUrl}?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                signal: syncInflight.signal,
            });

            if (!response.ok) {
                updateSyncIndicator('paused');
                schedulePoll(SYNC_ERROR_MS);
                return;
            }

            const data = await response.json();
            updateSyncIndicator('live');

            if (typeof data.cursor === 'number' && data.cursor >= cursor) {
                cursor = data.cursor;
                saveStoredCursor(workspaceId, cursor);
            }

            if (Array.isArray(data.events) && data.events.length > 0) {
                if (hasSyncedOnce) {
                    notifySyncEvents(data.events, workspaceId, seenEventIds, leadShowBase, workflowShowBase);
                } else {
                    markEventsSeen(workspaceId, data.events, seenEventIds);
                }
            }

            if (!data.changed) {
                hasSyncedOnce = true;
                sessionStorage.setItem(readyStorageKey(workspaceId), '1');
                schedulePoll(document.hidden ? SYNC_HIDDEN_MS : SYNC_ACTIVE_MS);
                return;
            }

            version = data.version;
            hasSyncedOnce = true;
            sessionStorage.setItem(readyStorageKey(workspaceId), '1');

            if (leadsBody && Array.isArray(data.leads)) {
                syncTableBody(leadsBody, data.leads, renderLeadRow, [leadShowBase]);
            }

            if (pipelineLeadsBody && Array.isArray(data.leads)) {
                syncTableBody(pipelineLeadsBody, data.leads, renderPipelineLeadRow, [leadShowBase, csrfToken()]);
                reapplyPipelineLeadFilter();
            }

            if (aePipelineBody && Array.isArray(data.leads)) {
                const aeLeads = data.leads.filter((lead) => AE_PIPELINE_STAGES.has(lead.stage));
                syncTableBody(aePipelineBody, aeLeads, renderAePipelineRow, [leadShowBase]);
            }

            if (workflowsList && Array.isArray(data.workflows)) {
                if (syncModeIsPatch(workflowsList)) {
                    patchWorkflowCards(workflowsList, data.workflows, workflowShowBase);
                } else {
                    smoothHtmlUpdate(
                        workflowsList,
                        data.workflows.map((wf) => renderWorkflowCard(wf, workflowShowBase)).join('')
                    );
                }
            }

            if (teamList && Array.isArray(data.team)) {
                if (teamList.dataset.adminTeam === '1' || teamList.dataset.staticTeam === '1') {
                    updateMemberRows(teamList, data.team);
                } else {
                    smoothHtmlUpdate(teamList, renderTeam(data.team));
                }
            }

            applyWorkspaceAdminState(data);
            applySalesOpsSync(data);
            applyToolkitSync(data?.toolkit);

            if (workflowId && Array.isArray(data.workflows) && data.workflows.length > 0) {
                const wf = data.workflows[0];
                smoothHtmlUpdate(workflowStatus, renderWorkflowStatusPill(wf.status));
                smoothTextUpdate(
                    workflowProgress,
                    String(wf.attempted_leads ?? wf.enriched_leads ?? wf.processed_leads ?? 0)
                );
                smoothTextUpdate(workflowAssigned, String(wf.assigned_leads ?? 0));
                smoothTextUpdate(workflowPendingReview, String(wf.pending_verification ?? 0));
                smoothTextUpdate(workflowPendingReview2, String(wf.pending_verification ?? 0));
                smoothWidthUpdate(workflowProgressBar, wf.completion_pct ?? 0);
                if (workflowProgressLabel) {
                    smoothTextUpdate(workflowProgressLabel, formatWorkflowProgressLabel(wf));
                }
                updatePipelineProgress(wf);
            }

            document.dispatchEvent(new CustomEvent('workspace:sync', { detail: data }));
            schedulePoll(document.hidden ? SYNC_HIDDEN_MS : SYNC_ACTIVE_MS);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            updateSyncIndicator('paused');
            console.debug('Workspace sync poll failed', error);
            schedulePoll(SYNC_ERROR_MS);
        }
    }

    syncVisibilityHandler = () => {
        if (!document.hidden) {
            schedulePoll(0);
        }
    };
    document.addEventListener('visibilitychange', syncVisibilityHandler);

    schedulePoll(0);
}
