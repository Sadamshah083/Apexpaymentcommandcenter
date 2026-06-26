import { showToast } from './toast.js';
import { updateMemberRows } from './member-management.js';
import { isOsNotificationsEnabled, showOsNotification } from './system-notifications.js';

const STAGE_CLASSES = {
    closed_won: 'bg-emerald-50 text-emerald-700',
    closed_lost: 'bg-rose-50 text-rose-700',
    follow_up: 'bg-amber-50 text-amber-700',
    interested: 'bg-indigo-50 text-indigo-700',
    lead: 'bg-slate-100 text-slate-700',
    contacted: 'bg-sky-50 text-sky-700',
};

const WORKFLOW_STATUS_CLASSES = {
    completed: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-rose-100 text-rose-800',
    extracting: 'bg-amber-100 text-amber-800 animate-pulse',
    mapping: 'bg-blue-100 text-blue-800',
    pending: 'bg-slate-200 text-slate-700',
    paused: 'bg-orange-100 text-orange-800',
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
]);

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function workflowActionForms(workflow, showBase) {
    const pauseForm = ['pending', 'extracting'].includes(workflow.status)
        ? `<form method="POST" action="${showBase}/${workflow.id}/pause" class="inline">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="text-xs text-amber-700 hover:text-amber-900 font-semibold">Stop</button>
           </form>`
        : '';
    const resumeForm = workflow.status === 'paused'
        ? `<form method="POST" action="${showBase}/${workflow.id}/resume" class="inline">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-800 font-semibold">Resume</button>
           </form>`
        : '';
    const deleteForm = `<form method="POST" action="${showBase}/${workflow.id}" class="inline" onsubmit="return confirm('Delete this pipeline and all lead records from the database?')">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <input type="hidden" name="_method" value="DELETE">
                <button type="submit" class="text-xs text-rose-600 hover:text-rose-800 font-semibold ml-2">Delete</button>
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

function renderLeadRow(lead, leadShowBase) {
    const stageClass = STAGE_CLASSES[lead.stage] || STAGE_CLASSES.lead;
    const contact = lead.direct_email && lead.direct_email !== 'Not Publicly Available'
        ? `<div class="text-slate-700">${escapeHtml(lead.direct_email)}</div>`
        : '';
    const phone = lead.direct_phone && lead.direct_phone !== 'Not Publicly Available'
        ? `<div class="text-xs text-slate-400 mt-0.5">${escapeHtml(lead.direct_phone)}</div>`
        : '';
    const contactFallback = (!lead.direct_email && !lead.direct_phone)
        ? '<span class="text-xs text-slate-400 font-italic">None available</span>'
        : '';

    return `
        <tr class="hover:bg-slate-50/80 transition-colors" data-lead-id="${lead.id}">
            <td class="py-3.5 px-4">
                <div class="font-bold text-slate-800">${escapeHtml(lead.business_name)}</div>
                ${lead.address ? `<div class="text-xs text-slate-500 mt-0.5">${escapeHtml(lead.address)}</div>` : ''}
                <div class="text-[10px] text-slate-400 font-normal mt-0.5">${escapeHtml(lead.city)}, ${escapeHtml(lead.state)}</div>
            </td>
            <td class="py-3.5 px-4 font-medium text-slate-600">${escapeHtml(lead.owner_name || 'Not Found')}</td>
            <td class="py-3.5 px-4">${contact}${phone}${contactFallback}</td>
            <td class="py-3.5 px-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                    ${escapeHtml(lead.payment_processor || 'Unknown')}
                </span>
            </td>
            <td class="py-3.5 px-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${stageClass}">
                    ${escapeHtml(stageLabel(lead.stage))}
                </span>
            </td>
            <td class="py-3.5 px-4 text-right">
                <a href="${leadShowBase}/${lead.id}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-colors">✎</a>
            </td>
        </tr>
    `;
}

function renderWorkflowCard(workflow, showBase) {
    const statusClass = WORKFLOW_STATUS_CLASSES[workflow.status] || WORKFLOW_STATUS_CLASSES.pending;

    return `
        <div class="p-4 rounded-xl bg-slate-50/50 border border-slate-100 hover:border-indigo-100 transition-colors relative group" data-workflow-id="${workflow.id}">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-bold text-slate-800 text-sm truncate max-w-[180px]">${escapeHtml(workflow.name)}</h3>
                    <p class="text-[11px] text-slate-400 truncate mt-0.5">${escapeHtml(workflow.original_filename || '')}</p>
                </div>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider ${statusClass}">
                    ${escapeHtml(workflow.status)}
                </span>
            </div>
            <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                <span>Processed: <strong class="text-slate-800">${workflow.processed_leads}</strong> / ${workflow.total_leads}</span>
            </div>
            <div class="mt-3 flex items-center justify-end gap-2 flex-wrap">
                <a href="${showBase}/${workflow.id}" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold">View mapping</a>
                ${workflowActionForms(workflow, showBase)}
            </div>
        </div>
    `;
}

function renderTeam(members) {
    return members.map((member) => `
        <div class="py-3 flex items-center justify-between" data-member-id="${member.id}">
            <div>
                <div class="font-bold text-slate-800 text-sm">${escapeHtml(member.name)}</div>
                <div class="text-xs text-slate-400 mt-0.5">${escapeHtml(member.email)}</div>
            </div>
            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 text-slate-600">
                ${escapeHtml(member.role)}${member.status !== 'active' ? ` (${member.status})` : ''}
            </span>
        </div>
    `).join('');
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

    const url = event.entity_type === 'workflow_lead' && event.entity_id
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
    const workflowId = root.dataset.workspaceWorkflowId || null;
    const leadShowBase = root.dataset.leadShowBase || '/portal/leads';
    const workflowShowBase = root.dataset.workflowShowBase || '/admin/workflows';

    const leadsBody = document.getElementById('workspace-sync-leads-body');
    const workflowsList = document.getElementById('workspace-sync-workflows');
    const teamList = document.getElementById('workspace-sync-team');
    const workflowStatus = document.getElementById('workspace-sync-workflow-status');
    const workflowProgress = document.getElementById('workspace-sync-workflow-progress');
    const workflowAssigned = document.getElementById('workspace-sync-workflow-assigned');

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

            const response = await fetch(`${syncUrl}?${params.toString()}`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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
                const leadsHtml = data.leads.length === 0
                    ? ''
                    : data.leads.map((lead) => renderLeadRow(lead, leadShowBase)).join('');
                smoothHtmlUpdate(leadsBody, leadsHtml);
            }

            if (workflowsList && Array.isArray(data.workflows)) {
                smoothHtmlUpdate(
                    workflowsList,
                    data.workflows.map((wf) => renderWorkflowCard(wf, workflowShowBase)).join(''),
                );
            }

            if (teamList && Array.isArray(data.team)) {
                if (teamList.dataset.staticTeam === '1') {
                    updateMemberRows(teamList, data.team);
                } else {
                    smoothHtmlUpdate(teamList, renderTeam(data.team));
                }
            }

            if (workflowId && Array.isArray(data.workflows) && data.workflows.length > 0) {
                const wf = data.workflows[0];
                smoothTextUpdate(workflowStatus, `Status: ${wf.status}`);
                smoothTextUpdate(workflowProgress, String(wf.processed_leads ?? 0));
                smoothTextUpdate(workflowAssigned, String(wf.assigned_leads ?? 0));
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
