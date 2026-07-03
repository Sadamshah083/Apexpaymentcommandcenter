import { showToast } from './toast.js';
import { updateMemberRows } from './member-management.js';
import { isOsNotificationsEnabled, showOsNotification } from './system-notifications.js';
import { applyWorkspaceAdminState } from './workspace-admin.js';
import { applySalesOpsSync, initAjaxActivityForms, TIER_LABELS, smoothWidthUpdate, applyToolkitSync } from './sales-ops-sync.js';

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

const IMPORT_ACTION_ICONS = {
    view: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>',
    pause: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6"/></svg>',
    resume: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    setup: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    delete: '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
};

function getWorkflowAssignRemaining(workflow) {
    const ready = Number(workflow.ready_to_assign ?? 0);
    if (ready > 0) {
        return ready;
    }

    const enriched = Number(workflow.enriched_leads ?? 0);
    const assigned = Number(workflow.assigned_leads ?? 0);

    return Math.max(0, enriched - assigned);
}

function workflowCanAssign(workflow) {
    if (workflow.status === 'mapping' || workflow.status === 'failed') {
        return false;
    }

    return getWorkflowAssignRemaining(workflow) > 0;
}

function workflowActionForms(workflow, showBase) {
    const pauseForm = ['pending', 'extracting'].includes(workflow.status)
        ? `<form method="POST" action="${showBase}/${workflow.id}/pause">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="import-action-btn" title="Pause" aria-label="Pause">${IMPORT_ACTION_ICONS.pause}</button>
           </form>`
        : '';
    const resumeForm = workflow.status === 'paused'
        ? `<form method="POST" action="${showBase}/${workflow.id}/resume">
                <input type="hidden" name="_token" value="${escapeHtml(csrfToken())}">
                <button type="submit" class="import-action-btn" title="Resume" aria-label="Resume">${IMPORT_ACTION_ICONS.resume}</button>
           </form>`
        : '';
    const setupLink = workflow.status === 'mapping'
        ? `<a href="${showBase}/${workflow.id}" class="import-action-btn" title="Setup" aria-label="Setup">${IMPORT_ACTION_ICONS.setup}</a>`
        : '';
    const deleteBtn = `<button
            type="button"
            class="import-action-btn import-action-btn-danger"
            title="Delete"
            aria-label="Delete"
            data-import-delete-open
            data-workflow-id="${workflow.id}"
            data-workflow-name="${escapeHtml(workflow.name)}"
            data-workflow-total="${workflow.total_leads ?? 0}"
        >${IMPORT_ACTION_ICONS.delete}</button>`;

    return `<div class="import-workflows-actions">
        <a href="${showBase}/${workflow.id}" class="import-action-btn" title="View" aria-label="View">${IMPORT_ACTION_ICONS.view}</a>
        ${pauseForm}${resumeForm}${setupLink}${deleteBtn}
    </div>`;
}

function renderWorkflowProgressCell(workflow) {
    const total = Number(workflow.total_leads ?? 0);
    const enriched = Number(workflow.enriched_leads ?? 0);
    const failed = Number(workflow.failed_leads ?? 0);
    const attempted = Number(workflow.attempted_leads ?? enriched + failed);
    const active = ['pending', 'extracting', 'paused'].includes(workflow.status);
    const pct = total > 0 ? Math.min(100, Math.round((attempted / total) * 100)) : 0;

    if (total === 0 && workflow.status === 'mapping') {
        return '<td data-label="Progress" class="col-progress min-w-[148px]"><span class="text-xs text-zinc-400">Awaiting setup</span></td>';
    }

    const fillClass = active ? '' : ' bg-emerald-500';

    return `<td data-label="Progress" class="col-progress min-w-[148px]">
        <div class="space-y-1">
            <div class="app-progress-track h-1.5">
                <div class="app-progress-fill${fillClass}" style="width: ${pct}%"></div>
            </div>
            <p class="text-[10px] text-zinc-500 import-workflow-progress-text">${attempted.toLocaleString()} / ${total.toLocaleString()} enriched</p>
        </div>
    </td>`;
}

function renderWorkflowAssignCell(workflow) {
    const remaining = getWorkflowAssignRemaining(workflow);
    const canAssign = workflowCanAssign(workflow);

    if (!canAssign) {
        return '<td data-label="Assign" class="col-assign"><span class="import-assign-empty">&mdash;</span></td>';
    }

    return `<td data-label="Assign" class="col-assign">${renderWorkflowAssignButton(workflow, remaining)}</td>`;
}

function renderWorkflowAssignButton(workflow, remaining = null) {
    const ready = remaining ?? getWorkflowAssignRemaining(workflow);

    return `<button
            type="button"
            class="app-btn app-btn-primary app-btn-sm import-assign-btn"
            data-import-assign-open
            data-workflow-id="${workflow.id}"
            data-workflow-name="${escapeHtml(workflow.name)}"
            data-workflow-total="${workflow.total_leads ?? 0}"
            data-workflow-enriched="${workflow.enriched_leads ?? 0}"
            data-workflow-assigned="${workflow.assigned_leads ?? 0}"
            data-workflow-remaining="${ready}"
        >Assign</button>`;
}

function patchWorkflowAssignCell(cell, workflow) {
    if (!cell) {
        return;
    }

    const remaining = getWorkflowAssignRemaining(workflow);
    const canAssign = workflowCanAssign(workflow);
    const existingBtn = cell.querySelector('[data-import-assign-open]');

    if (canAssign) {
        if (existingBtn) {
            existingBtn.dataset.workflowId = String(workflow.id);
            existingBtn.dataset.workflowName = workflow.name ?? '';
            existingBtn.dataset.workflowTotal = String(workflow.total_leads ?? 0);
            existingBtn.dataset.workflowEnriched = String(workflow.enriched_leads ?? 0);
            existingBtn.dataset.workflowAssigned = String(workflow.assigned_leads ?? 0);
            existingBtn.dataset.workflowRemaining = String(remaining);
            return;
        }

        cell.innerHTML = renderWorkflowAssignButton(workflow, remaining);
        return;
    }

    if (!existingBtn && !cell.querySelector('.import-assign-empty')) {
        cell.innerHTML = '<span class="import-assign-empty">&mdash;</span>';
    }
}

function patchWorkflowActionsCell(cell, workflow, showBase) {
    if (!cell) {
        return;
    }

    const row = cell.closest('tr');
    const status = workflow.status || '';
    const hasActions = cell.querySelector('.import-workflows-actions');

    if (row && row.dataset.workflowStatus === status && hasActions) {
        const deleteBtn = cell.querySelector('[data-import-delete-open]');
        if (deleteBtn) {
            deleteBtn.dataset.workflowName = workflow.name ?? '';
            deleteBtn.dataset.workflowTotal = String(workflow.total_leads ?? 0);
        }
        return;
    }

    if (row) {
        row.dataset.workflowStatus = status;
    }

    cell.innerHTML = workflowActionForms(workflow, showBase);
}

function patchImportStatCell(el, value) {
    if (!el) {
        return;
    }

    const next = String(value ?? '');
    if (el.textContent !== next) {
        el.textContent = next;
    }
}

function formatRelativeTime(isoString) {
    if (!isoString) {
        return 'ΓÇö';
    }

    const date = new Date(isoString);
    if (Number.isNaN(date.getTime())) {
        return 'ΓÇö';
    }

    const diffSec = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(diffSec);
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

    if (abs < 60) {
        return rtf.format(Math.round(diffSec), 'second');
    }
    if (abs < 3600) {
        return rtf.format(Math.round(diffSec / 60), 'minute');
    }
    if (abs < 86400) {
        return rtf.format(Math.round(diffSec / 3600), 'hour');
    }

    return rtf.format(Math.round(diffSec / 86400), 'day');
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

function renderLeadCampaignMeta(lead) {
    const campaignHtml = lead.campaign_name
        ? `<span class="campaign-chip campaign-chip--sm">${escapeHtml(lead.campaign_name)}</span>`
        : '';
    const listHtml = lead.lead_list_name
        ? `<div class="text-[10px] text-zinc-400 mt-0.5">List: <span class="font-medium text-zinc-600">${escapeHtml(lead.lead_list_name)}</span></div>`
        : '';

    return `${campaignHtml}${listHtml ? `<div class="mt-0.5">${listHtml}</div>` : ''}`;
}

function campaignChipHtml(campaignId, campaignName, campaignBase) {
    if (!campaignName) {
        return '';
    }

    const href = campaignId && campaignBase
        ? `${campaignBase}/${campaignId}`
        : null;

    return href
        ? `<a href="${escapeHtml(href)}" class="campaign-chip campaign-chip--sm mt-1">${escapeHtml(campaignName)}</a>`
        : `<span class="campaign-chip campaign-chip--sm mt-1">${escapeHtml(campaignName)}</span>`;
}

function workflowImportCellHtml(workflow, campaignBase) {
    const listLine = workflow.lead_list_name
        ? `<div class="import-workflow-meta">List: ${escapeHtml(workflow.lead_list_name)}</div>`
        : '';

    return `
        <div class="import-workflow-name">${escapeHtml(workflow.name)}</div>
        ${listLine}
        ${campaignChipHtml(workflow.campaign_id, workflow.campaign_name, campaignBase)}
    `;
}

function renderCommandCenterLeadRow(lead, leadShowBase, campaignBase) {
    const display = resolveLeadDisplay(lead);
    const location = [lead.city, lead.state].filter(Boolean).join(', ');
    const campaignCell = lead.campaign_name
        ? campaignChipHtml(lead.campaign_id, lead.campaign_name, campaignBase)
        : '<span class="text-zinc-400">—</span>';

    return `
        <tr data-lead-id="${lead.id}">
            <td>
                <div class="font-bold text-zinc-900">${escapeHtml(lead.business_name)}</div>
                ${location ? `<div class="text-xs text-zinc-400">${escapeHtml(location)}</div>` : ''}
            </td>
            <td>${campaignCell}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.phone)}</td>
            <td><span class="app-badge app-badge-muted">${escapeHtml(lead.pipeline_phase_label || lead.stage_label || stageLabel(lead.stage))}</span></td>
            <td class="text-right">
                <a href="${leadShowBase}/${lead.id}" class="app-btn app-btn-secondary app-btn-sm">Open</a>
            </td>
        </tr>
    `;
}

function resolveLeadDisplay(lead) {
    const email = lead.display_email
        || (lead.direct_email && lead.direct_email !== 'Not Publicly Available' ? lead.direct_email : '')
        || (lead.input_email || '');
    const phone = lead.display_phone
        || (lead.direct_phone && lead.direct_phone !== 'Not Publicly Available' ? lead.direct_phone : '')
        || (lead.input_phone || '');
    const socialMedia = lead.display_social_media || '';
    const website = lead.display_website || lead.website || '';

    return {
        email: String(email || '').trim(),
        phone: String(phone || '').trim(),
        socialMedia: String(socialMedia || '').trim(),
        website: String(website || '').trim(),
    };
}

function formatLeadCell(value) {
    const trimmed = String(value || '').trim();
    return trimmed ? escapeHtml(trimmed) : '<span class="text-zinc-400">ΓÇö</span>';
}

function resolveLeadContact(lead) {
    const display = resolveLeadDisplay(lead);
    return {
        email: display.email,
        phone: display.phone,
        website: display.website,
        socialMedia: display.socialMedia,
    };
}

function formatLeadCellInner(value) {
    const trimmed = String(value || '').trim();
    return trimmed ? escapeHtml(trimmed) : '<span class="text-zinc-400">—</span>';
}

function portalStatusHtml(lead, statusColumn) {
    if (statusColumn === 'closer') {
        return escapeHtml(lead.closer_status_label || '—');
    }

    if (statusColumn === 'both') {
        const setter = escapeHtml(lead.setter_status_label || '—');
        const closer = lead.closer_status
            ? `<span class="text-zinc-400"> / ${escapeHtml(lead.closer_status_label || '')}</span>`
            : '';

        return `${setter}${closer}`;
    }

    return escapeHtml(lead.setter_status_label || '—');
}

function portalAssigneeHtml(lead) {
    if (lead.pipeline_phase === 'enriched' && !lead.assigned_user_id) {
        return '<span class="text-amber-700 font-medium">Unassigned</span>';
    }

    return escapeHtml(lead.assignee_name || lead.setter_name || '—');
}

function patchPortalLeadCell(row, col, html) {
    const cell = row.querySelector(`[data-col="${col}"]`);
    if (!cell || cell.innerHTML.trim() === html.trim()) {
        return;
    }

    cell.innerHTML = html;
    cell.classList.add('live-sync-flash');
    window.setTimeout(() => cell.classList.remove('live-sync-flash'), 320);
}

function patchPortalLeadRows(tbody, leads) {
    if (!tbody || !Array.isArray(leads) || leads.length === 0) {
        return;
    }

    const statusColumn = tbody.dataset.statusColumn || 'setter';
    const showAssignee = tbody.dataset.showAssignee === '1';
    const showSetterNotes = tbody.dataset.showSetterNotes === '1';
    const byId = new Map(leads.map((lead) => [String(lead.id), lead]));

    tbody.querySelectorAll('tr[data-lead-id]').forEach((row) => {
        const lead = byId.get(row.dataset.leadId);
        if (!lead) {
            return;
        }

        const display = resolveLeadDisplay(lead);

        patchPortalLeadCell(row, 'email', formatLeadCellInner(display.email));
        patchPortalLeadCell(row, 'social', formatLeadCellInner(display.socialMedia));
        patchPortalLeadCell(row, 'contact', formatLeadCellInner(display.phone));
        patchPortalLeadCell(row, 'phase', escapeHtml(lead.pipeline_phase_label || ''));
        patchPortalLeadCell(row, 'status', portalStatusHtml(lead, statusColumn));

        if (showAssignee) {
            patchPortalLeadCell(row, 'assignee', portalAssigneeHtml(lead));
        }

        if (showSetterNotes) {
            const notesHtml = lead.handoff_notes
                ? `<p class="line-clamp-3 whitespace-pre-wrap">${escapeHtml(lead.handoff_notes)}</p>`
                : '<span class="text-zinc-400">—</span>';
            patchPortalLeadCell(row, 'setter_notes', notesHtml);
        }

        if (tbody.dataset.showEditableSetter === '1') {
            const updateCell = row.querySelector('[data-col="update"]');
            if (updateCell && lead.is_setter_locked) {
                updateCell.innerHTML = '<span class="text-xs text-zinc-400">—</span>';
            }
        }
    });
}

function renderLeadRow(lead, leadShowBase) {
    const display = resolveLeadDisplay(lead);
    const tierLabel = lead.tier_label || TIER_LABELS[lead.tier] || '';
    const detailsLink = `<a href="${leadShowBase}/${lead.id}" class="app-btn app-btn-secondary app-btn-sm">Details</a>`;

    return `
        <tr data-lead-id="${lead.id}">
            <td>
                <div class="font-bold text-zinc-900">${escapeHtml(lead.business_name)}</div>
                ${lead.address ? `<div class="text-xs text-zinc-500 mt-0.5">${escapeHtml(lead.address)}</div>` : ''}
                <div class="text-[10px] text-zinc-400 font-normal mt-0.5">${escapeHtml(lead.city)}, ${escapeHtml(lead.state)}</div>
                ${renderLeadCampaignMeta(lead)}
            </td>
            <td class="font-medium text-zinc-600">${formatLeadCell(lead.display_owner || lead.owner_name)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.email)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.socialMedia)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.phone)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(lead.display_processor || lead.payment_processor)}</td>
            <td>
                <span class="app-badge app-badge-muted">${escapeHtml(lead.pipeline_phase_label || lead.stage_label || stageLabel(lead.stage))}</span>
            </td>
            <td class="text-right">
                ${detailsLink}
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
    const failureNote = lead.status === 'failed' && lead.error_message
        ? `<div class="text-xs text-rose-600 mt-1 max-w-xs">${escapeHtml(lead.error_message).slice(0, 120)}</div>`
        : '';
    const detailsLink = `<a href="${leadShowBase}/${lead.id}" class="app-btn app-btn-secondary app-btn-sm">Details</a>`;
    const actions = lead.status === 'pending_verification'
        ? `<div class="flex items-center justify-end gap-1 flex-wrap">
                ${detailsLink}
                <form method="POST" action="/admin/leads/${lead.id}/approve">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="app-btn app-btn-success app-btn-sm">Approve</button>
                </form>
                <form method="POST" action="/admin/leads/${lead.id}/reject">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="app-btn app-btn-ghost-danger app-btn-sm">Reject</button>
                </form>
           </div>`
        : (lead.status === 'completed'
            ? `<div class="flex items-center justify-end gap-2">${detailsLink}<span class="text-xs font-semibold text-emerald-700">Released</span></div>`
            : detailsLink);

    const display = resolveLeadDisplay(lead);

    return `
        <tr data-lead-id="${lead.id}" data-lead-status="${escapeHtml(lead.status || '')}">
            <td>
                <a href="${leadShowBase}/${lead.id}" class="font-bold text-zinc-900 hover:underline">${escapeHtml(lead.business_name)}</a>
                ${location ? `<div class="text-xs text-zinc-400 mt-0.5">${escapeHtml(location)}</div>` : ''}
                ${renderLeadCampaignMeta(lead)}
            </td>
            <td class="text-sm text-zinc-600">${formatLeadCell(lead.display_owner || lead.owner_name)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.email)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.socialMedia)}</td>
            <td class="text-sm text-zinc-600">${formatLeadCell(display.phone)}</td>
            <td><span class="${pipelineStatusClass(lead.status)}">${escapeHtml(status)}</span>${failureNote}</td>
            <td class="text-right whitespace-nowrap">${actions}</td>
        </tr>
    `;
}

function renderWorkflowRow(workflow, showBase, campaignBase = '') {
    const remaining = getWorkflowAssignRemaining(workflow);

    return `
        <tr data-workflow-id="${workflow.id}">
            <td data-label="Import name" class="col-import-name">
                ${workflowImportCellHtml(workflow, campaignBase)}
            </td>
            <td data-label="File" class="col-file import-workflow-file" title="${escapeHtml(workflow.original_filename || '')}">${escapeHtml(workflow.original_filename || '')}</td>
            <td data-label="Status" class="col-status">${renderWorkflowStatusPill(workflow.status)}</td>
            ${renderWorkflowProgressCell(workflow)}
            <td data-label="Total" class="col-total import-workflow-stat">${Number(workflow.total_leads ?? 0).toLocaleString()}</td>
            <td data-label="Enriched" class="col-enriched import-workflow-stat">${Number(workflow.enriched_leads ?? 0).toLocaleString()}</td>
            <td data-label="Assigned" class="col-assigned import-workflow-stat import-workflow-stat-success">${Number(workflow.assigned_leads ?? 0).toLocaleString()}</td>
            <td data-label="Remaining" class="col-remaining import-workflow-stat import-workflow-stat-warning">${remaining.toLocaleString()}</td>
            ${renderWorkflowAssignCell(workflow)}
            <td data-label="Actions" class="col-actions text-right">${workflowActionForms(workflow, showBase)}</td>
        </tr>
    `;
}

function renderTeam(members) {
    return members.map((member) => `
        <div class="py-3 flex items-center justify-between" data-member-id="${member.id}">
            <div>
                <div class="font-bold text-zinc-900 text-sm">${escapeHtml(member.name)}</div>
                <div class="text-xs text-zinc-400 mt-0.5">${escapeHtml(member.email)}</div>
            </div>
            <span class="app-badge app-badge-muted">
                ${escapeHtml(member.role)}${member.status !== 'active' ? ` (${member.status})` : ''}
            </span>
        </div>
    `).join('');
}

const AE_PIPELINE_STAGES = new Set(['meeting_scheduled', 'proposal_sent', 'follow_up', 'closed_won', 'closed_lost']);

function renderAePipelineRow(lead, leadShowBase) {
    const volume = lead.monthly_processing_volume
        ? `$${Number(lead.monthly_processing_volume).toLocaleString()}`
        : 'ΓÇö';
    const meeting = lead.schedule_at || 'ΓÇö';

    return `
        <tr data-lead-id="${lead.id}">
            <td class="font-bold">${escapeHtml(lead.business_name)}</td>
            <td>${escapeHtml(lead.owner_name || 'ΓÇö')}</td>
            <td>${escapeHtml(lead.stage_label || stageLabel(lead.stage))}</td>
            <td>${volume}</td>
            <td>${escapeHtml(lead.current_processor || lead.payment_processor || 'ΓÇö')}</td>
            <td class="text-xs">${escapeHtml(meeting)}</td>
            <td><a href="${leadShowBase}/${lead.id}" class="app-link text-sm">Details</a></td>
        </tr>
    `;
}

const PIPELINE_STEP_CHECK = '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>';

function syncModeIsPatch(el) {
    return el?.dataset?.syncMode === 'patch';
}

function syncModeIsStatic(el) {
    return el?.dataset?.syncMode === 'static';
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
    if (!tbody || !Array.isArray(items) || syncModeIsStatic(tbody)) {
        return;
    }

    if (items.length === 0 && !syncModeIsPatch(tbody) && tbody.querySelector('tr[data-lead-id], tr[data-workflow-id]')) {
        return;
    }

    if (syncModeIsPatch(tbody)) {
        patchTableRows(tbody, items, renderRow, renderArgs);
        return;
    }

    const html = items.length === 0
        ? ''
        : items.map((item) => renderRow(item, ...renderArgs)).join('');
    smoothHtmlUpdate(tbody, html);
}

function cellFromRender(html) {
    const temp = document.createElement('tbody');
    temp.innerHTML = html.trim();
    return temp.querySelector('td');
}

function patchWorkflowRowCells(row, workflow, showBase, campaignBase = '') {
    const expectedCols = Number(row.closest('tbody')?.dataset?.expectedCols || 10);

    if (row.cells.length !== expectedCols) {
        const newRow = rowFromHtml(renderWorkflowRow(workflow, showBase, campaignBase));
        if (newRow) {
            row.replaceWith(newRow);
        }
        return;
    }

    const cells = row.cells;
    const nameHtml = workflowImportCellHtml(workflow, campaignBase);
    if (cells[0].innerHTML.trim() !== nameHtml.trim()) {
        cells[0].innerHTML = nameHtml;
    }

    const statusHtml = renderWorkflowStatusPill(workflow.status);
    if (cells[2].innerHTML.trim() !== statusHtml.trim()) {
        cells[2].innerHTML = statusHtml;
    }

    const progressCell = cellFromRender(renderWorkflowProgressCell(workflow));
    if (progressCell && cells[3].innerHTML !== progressCell.innerHTML) {
        cells[3].innerHTML = progressCell.innerHTML;
        cells[3].className = progressCell.className;
    }

    patchImportStatCell(cells[4], Number(workflow.total_leads ?? 0).toLocaleString());
    patchImportStatCell(cells[5], Number(workflow.enriched_leads ?? 0).toLocaleString());
    patchImportStatCell(cells[6], Number(workflow.assigned_leads ?? 0).toLocaleString());
    patchImportStatCell(cells[7], getWorkflowAssignRemaining(workflow).toLocaleString());

    patchWorkflowAssignCell(cells[8], workflow);

    patchWorkflowActionsCell(cells[9], workflow, showBase);
}

function workflowsVisibleOnPage(tbody, workflows) {
    if (!Array.isArray(workflows)) {
        return [];
    }

    const visibleIds = new Set();
    tbody?.querySelectorAll('tr[data-workflow-id]').forEach((row) => {
        visibleIds.add(String(row.dataset.workflowId));
    });

    return workflows.filter((workflow) => visibleIds.has(String(workflow.id)));
}

function syncWorkflowTableBody(tbody, workflows, showBase) {
    if (!Array.isArray(workflows)) {
        return;
    }

    const staticMode = syncModeIsStatic(tbody);
    if (staticMode && !tbody) {
        return;
    }

    const campaignBase = tbody?.dataset?.campaignShowBase || '';
    const pageWorkflows = workflowsVisibleOnPage(tbody, workflows);
    if (pageWorkflows.length === 0) {
        return;
    }

    const syncMode = tbody?.dataset?.syncMode || 'patch';
    const byId = new Map(pageWorkflows.map((wf) => [String(wf.id), wf]));

    if (tbody && !syncModeIsStatic(tbody)) {
        tbody.querySelectorAll('tr[data-workflow-id]').forEach((row) => {
            const wf = byId.get(String(row.dataset.workflowId));
            if (!wf) {
                return;
            }

            if (syncMode === 'cells' || syncMode === 'patch') {
                patchWorkflowRowCells(row, wf, showBase, campaignBase);
                return;
            }

            const newRow = rowFromHtml(renderWorkflowRow(wf, showBase, campaignBase));
            if (newRow && newRow.outerHTML !== row.outerHTML) {
                row.replaceWith(newRow);
            }
        });
    }
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
    const done = (wf.attempted_leads ?? ((wf.enriched_leads ?? 0) + (wf.failed_leads ?? 0)));
    return `${wf.completion_pct ?? 0}% ┬╖ ${done} / ${wf.total_leads ?? 0}`;
}

const SYNC_ACTIVE_MS = 2000;
const SYNC_LITE_MS = 15000;
const SYNC_HIDDEN_MS = 10000;
const SYNC_ERROR_MS = 4000;

function updateSyncIndicator(state) {
    const indicator = document.getElementById('workspace-sync-indicator');
    if (!indicator) {
        return;
    }

    indicator.classList.toggle('is-paused', state === 'paused');
    indicator.classList.toggle('is-syncing', false);

    const text = indicator.querySelector('.app-topnav-status-text');
    if (text) {
        const labels = {
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

let syncEventSource = null;
let syncReconnectTimer = null;
let syncVisibilityHandler = null;
let syncRequestHandler = null;
let syncPollTimer = null;
let syncPollAborted = false;
const SYNC_LIST_POLL_MS = 8000;
const SYNC_FULL_POLL_MS = 5000;
const SYNC_HIDDEN_POLL_MS = 30000;

const SYNC_TARGET_IDS = [
    'workspace-sync-leads-body',
    'workspace-sync-portal-leads-body',
    'workspace-sync-handoff-queue-body',
    'workspace-sync-workflows',
    'workspace-sync-pipeline-leads',
    'workspace-sync-team',
    'workspace-sync-workflow-status',
    'workspace-sync-ae-pipeline-body',
];

function pageNeedsWorkspaceSync() {
    if (document.getElementById('portal-sync-context')) {
        return false;
    }

    const root = document.body;
    if (root.dataset.workspaceSyncScope === 'lite' && root.dataset.workspaceSyncUrl) {
        return true;
    }

    const pageContext = document.getElementById('workspace-sync-page');
    if (pageContext?.dataset.syncScope === 'off') {
        return false;
    }

    return SYNC_TARGET_IDS.some((id) => document.getElementById(id));
}

export function teardownWorkspaceSync() {
    syncPollAborted = true;
    if (syncPollTimer) {
        window.clearTimeout(syncPollTimer);
        syncPollTimer = null;
    }
    if (syncReconnectTimer) {
        window.clearTimeout(syncReconnectTimer);
        syncReconnectTimer = null;
    }
    if (syncEventSource) {
        syncEventSource.close();
        syncEventSource = null;
    }
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
    syncPollAborted = false;

    const root = document.body;
    const streamUrl = root.dataset.workspaceSyncStreamUrl;
    const pollUrl = root.dataset.workspaceSyncUrl;
    const usePoll = root.dataset.workspaceSyncUsePoll === '1';
    const syncLite = root.dataset.workspaceSyncScope === 'lite';

    if (!pageNeedsWorkspaceSync()) {
        return;
    }

    if ((syncLite || usePoll) && !pollUrl) {
        return;
    }

    if (!syncLite && !usePoll && (!streamUrl || typeof EventSource === 'undefined')) {
        return;
    }

    const workspaceId = root.dataset.workspaceId || 'default';
    let version = null;
    let cursor = loadStoredCursor(workspaceId);
    let hasSyncedOnce = sessionStorage.getItem(readyStorageKey(workspaceId)) === '1';
    const seenEventIds = loadSeenEventIds(workspaceId);
    const pageContext = document.getElementById('workspace-sync-page');
    const workflowId = pageContext?.dataset.workflowId || root.dataset.workspaceWorkflowId || null;
    const leadId = pageContext?.dataset.leadId || root.dataset.workspaceLeadId || null;
    const syncScope = syncLite ? 'lite' : (pageContext?.dataset.syncScope || null);
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
    const portalLeadsBody = document.getElementById('workspace-sync-portal-leads-body');

    initAjaxActivityForms();

    function buildSyncUrl(base) {
        const params = new URLSearchParams();
        if (version) params.set('v', version);
        params.set('cursor', String(cursor));
        if (workflowId) params.set('workflow_id', workflowId);
        if (leadId) params.set('lead_id', leadId);
        if (syncLite) {
            params.set('scope', 'lite');
        } else if (syncScope) {
            params.set('sync_scope', syncScope);
        }

        return `${base}?${params.toString()}`;
    }

    function buildStreamUrl() {
        return buildSyncUrl(streamUrl);
    }

    function applySyncPayload(data) {
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
            return;
        }

        version = data.version;
        hasSyncedOnce = true;
        sessionStorage.setItem(readyStorageKey(workspaceId), '1');

        if (leadsBody && Array.isArray(data.leads) && syncScope !== 'list' && data.leads.length > 0) {
            const campaignBase = leadsBody.dataset.campaignShowBase || workflowsList?.dataset?.campaignShowBase || '';
            const leadRenderFn = leadsBody.dataset.syncLeadsFormat === 'command-center'
                ? renderCommandCenterLeadRow
                : renderLeadRow;
            const leadArgs = leadsBody.dataset.syncLeadsFormat === 'command-center'
                ? [leadShowBase, campaignBase]
                : [leadShowBase];
            syncTableBody(leadsBody, data.leads, leadRenderFn, leadArgs);
        }

        const portalSyncContext = document.getElementById('portal-sync-context');

        if (portalLeadsBody && Array.isArray(data.leads) && data.leads.length > 0 && !portalSyncContext) {
            patchPortalLeadRows(portalLeadsBody, data.leads);
        }

        if (pipelineLeadsBody && Array.isArray(data.leads)) {
            syncTableBody(pipelineLeadsBody, data.leads, renderPipelineLeadRow, [leadShowBase, csrfToken()]);
            reapplyPipelineLeadFilter();
        }

        if (aePipelineBody && Array.isArray(data.leads) && !portalSyncContext) {
            const aeLeads = data.leads.filter((lead) => AE_PIPELINE_STAGES.has(lead.stage));
            syncTableBody(aePipelineBody, aeLeads, renderAePipelineRow, [leadShowBase]);
        }

        if (workflowsList && Array.isArray(data.workflows) && workflowsList?.dataset.adminWorkflowsTable === '1') {
            syncWorkflowTableBody(workflowsList, data.workflows, workflowShowBase);
        }

        if (teamList && Array.isArray(data.team)) {
            if (teamList.dataset.adminTeam === '1' || teamList.dataset.staticTeam === '1') {
                updateMemberRows(teamList, data.team);
            } else {
                smoothHtmlUpdate(teamList, renderTeam(data.team));
            }
        }

        if (syncLite) {
            applyToolkitSync(data?.toolkit);
        } else if (syncScope !== 'list') {
            applyWorkspaceAdminState(data);
            applySalesOpsSync(data);
            applyToolkitSync(data?.toolkit);
        }

        if (workflowId && Array.isArray(data.workflows) && data.workflows.length > 0) {
            const wf = data.workflows[0];
            smoothHtmlUpdate(workflowStatus, renderWorkflowStatusPill(wf.status));
            smoothTextUpdate(workflowProgress, String(wf.attempted_leads ?? wf.enriched_leads ?? 0));
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
    }

    function scheduleReconnect(ms = 1500) {
        if (syncReconnectTimer) {
            window.clearTimeout(syncReconnectTimer);
        }
        syncReconnectTimer = window.setTimeout(connectStream, ms);
    }

    function schedulePoll(delayMs) {
        if (syncPollAborted) {
            return;
        }
        if (syncPollTimer) {
            window.clearTimeout(syncPollTimer);
        }
        syncPollTimer = window.setTimeout(pollTick, delayMs);
    }

    async function pollTick() {
        if (syncPollAborted || !pollUrl) {
            return;
        }

        const pollInterval = syncLite ? SYNC_LITE_MS : (syncScope === 'list' ? SYNC_LIST_POLL_MS : SYNC_FULL_POLL_MS);
        const hiddenInterval = SYNC_HIDDEN_POLL_MS;

        if (document.hidden) {
            schedulePoll(hiddenInterval);
            return;
        }

        try {
            const response = await fetch(buildSyncUrl(pollUrl), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (response.ok) {
                const data = await response.json();
                applySyncPayload(data);
            } else {
                updateSyncIndicator('paused');
            }
        } catch (error) {
            if (!syncPollAborted) {
                updateSyncIndicator('paused');
            }
        }

        schedulePoll(document.hidden ? hiddenInterval : pollInterval);
    }

    function connectPoll() {
        updateSyncIndicator('live');
        schedulePoll(0);
    }

    function connectStream() {
        if (syncEventSource) {
            syncEventSource.close();
            syncEventSource = null;
        }

        const source = new EventSource(buildStreamUrl());
        syncEventSource = source;

        source.onopen = () => {
            updateSyncIndicator('live');
        };

        source.onmessage = (event) => {
            try {
                applySyncPayload(JSON.parse(event.data));
            } catch (error) {
                console.debug('Workspace stream parse failed', error);
            }
        };

        source.addEventListener('reconnect', () => {
            source.close();
            if (syncEventSource === source) {
                syncEventSource = null;
            }
            scheduleReconnect(300);
        });

        source.onerror = () => {
            source.close();
            if (syncEventSource === source) {
                syncEventSource = null;
            }
            updateSyncIndicator('paused');
            scheduleReconnect(2000);
        };
    }

    function onSyncRequest() {
        if (usePoll) {
            connectPoll();
            return;
        }
        connectStream();
    }

    syncRequestHandler = onSyncRequest;
    document.addEventListener('workspace:sync-request', syncRequestHandler);

    syncVisibilityHandler = () => {
        if (document.hidden) {
            return;
        }
        if (usePoll) {
            if (!syncPollTimer && !syncPollAborted) {
                schedulePoll(0);
            }
            return;
        }
        if (!syncEventSource) {
            connectStream();
        }
    };
    document.addEventListener('visibilitychange', syncVisibilityHandler);

    if (syncLite || usePoll) {
        if (syncLite && 'requestIdleCallback' in window) {
            requestIdleCallback(connectPoll, { timeout: 2500 });
        } else {
            connectPoll();
        }
    } else {
        connectStream();
    }
}

function patchHandoffQueueRows(tbody, leads) {
    if (!tbody || !Array.isArray(leads)) {
        return;
    }

    let closers = [];
    try {
        closers = JSON.parse(tbody.dataset.closers || '[]');
    } catch {
        closers = [];
    }

    const leadShowBase = document.body?.dataset?.leadShowBase || '/portal/leads';
    const byId = new Map(leads.map((lead) => [String(lead.id), lead]));
    const existingIds = new Set();

    tbody.querySelectorAll('tr[data-lead-id]').forEach((row) => {
        existingIds.add(row.dataset.leadId);
        const lead = byId.get(row.dataset.leadId);
        if (!lead) {
            row.remove();
            return;
        }

        patchPortalLeadCell(row, 'setter', escapeHtml(lead.setter_name || '—'));
        const notesHtml = lead.handoff_notes
            ? `<p class="whitespace-pre-wrap line-clamp-4">${escapeHtml(lead.handoff_notes)}</p><a href="${leadShowBase}/${lead.id}" class="text-xs text-indigo-600 font-medium mt-1 inline-block">View full history</a>`
            : '<span class="text-zinc-400">—</span>';
        patchPortalLeadCell(row, 'notes', notesHtml);
    });

    leads.forEach((lead) => {
        if (existingIds.has(String(lead.id))) {
            return;
        }

        tbody.insertAdjacentHTML('beforeend', renderHandoffQueueRow(lead, closers, leadShowBase));
    });
}

function renderHandoffQueueRow(lead, closers, leadShowBase) {
    const location = [lead.city, lead.state].filter(Boolean).join(', ');
    const closerOptions = closers
        .map((closer) => `<option value="${closer.id}">${escapeHtml(closer.name)}</option>`)
        .join('');
    const notesHtml = lead.handoff_notes
        ? `<p class="whitespace-pre-wrap line-clamp-4">${escapeHtml(lead.handoff_notes)}</p><a href="${leadShowBase}/${lead.id}" class="text-xs text-indigo-600 font-medium mt-1 inline-block">View full history</a>`
        : '<span class="text-zinc-400">—</span>';

    return `
        <tr data-lead-id="${lead.id}">
            <td data-col="business">
                <a href="${leadShowBase}/${lead.id}" class="font-bold text-zinc-900 hover:underline">${escapeHtml(lead.business_name)}</a>
                ${location ? `<div class="text-xs text-zinc-400">${escapeHtml(location)}</div>` : ''}
            </td>
            <td class="text-sm" data-col="setter">${escapeHtml(lead.setter_name || '—')}</td>
            <td class="text-sm text-zinc-600 max-w-md align-top" data-col="notes">${notesHtml}</td>
            <td class="text-right">
                <form method="POST" action="${leadShowBase}/${lead.id}/assign-closer" class="flex items-center justify-end gap-2">
                    <input type="hidden" name="_token" value="${csrfToken()}">
                    <select name="closer_id" required class="app-input app-input-sm">
                        <option value="">Select closer…</option>
                        ${closerOptions}
                    </select>
                    <button type="submit" class="app-btn app-btn-primary app-btn-sm">Assign</button>
                </form>
            </td>
        </tr>
    `;
}

export function applyCommandCenterLeadsPatch(tbody, leads) {
    if (!tbody || !Array.isArray(leads) || leads.length === 0) {
        return;
    }

    const campaignBase = tbody.dataset.campaignShowBase || '';
    const leadShowBase = tbody.dataset.leadShowBase || document.body?.dataset?.leadShowBase || '/admin/leads';
    patchTableRows(tbody, leads, renderCommandCenterLeadRow, [leadShowBase, campaignBase]);

    const existingIds = new Set(
        [...tbody.querySelectorAll('tr[data-lead-id]')].map((row) => row.dataset.leadId)
    );
    leads.forEach((lead) => {
        if (existingIds.has(String(lead.id))) {
            return;
        }
        tbody.insertAdjacentHTML('beforeend', renderCommandCenterLeadRow(lead, leadShowBase, campaignBase));
    });
}

export function applyAePipelineLivePatch(tbody, leads) {
    if (!tbody || !Array.isArray(leads)) {
        return;
    }

    const leadShowBase = document.body?.dataset?.leadShowBase || '/portal/leads';
    patchTableRows(tbody, leads, renderAePipelineRow, [leadShowBase]);

    const existingIds = new Set(
        [...tbody.querySelectorAll('tr[data-lead-id]')].map((row) => row.dataset.leadId)
    );
    leads.forEach((lead) => {
        if (existingIds.has(String(lead.id))) {
            return;
        }
        tbody.insertAdjacentHTML('beforeend', renderAePipelineRow(lead, leadShowBase));
    });
}

export function applyPortalLeadsLivePatch(tbody, leads) {
    patchPortalLeadRows(tbody, leads);
}

export function applyHandoffQueueLivePatch(tbody, leads) {
    patchHandoffQueueRows(tbody, leads);
}

if (typeof window !== 'undefined') {
    window.applyCommandCenterLeadsPatch = applyCommandCenterLeadsPatch;
}
