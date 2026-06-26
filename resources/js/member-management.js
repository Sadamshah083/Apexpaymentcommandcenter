import { showToast } from './toast.js';

const ACTION_COPY = {
    suspend: {
        title: 'Suspend account?',
        tone: 'warning',
        confirmLabel: 'Suspend account',
        message: (name) => `${name} will immediately lose access to the agent portal. Their assigned leads stay in the workspace until you reassign them.`,
    },
    reactivate: {
        title: 'Reactivate account?',
        tone: 'success',
        confirmLabel: 'Reactivate account',
        message: (name) => `${name} will be able to sign in to the agent portal again.`,
    },
    remove: {
        title: 'Remove member?',
        tone: 'error',
        confirmLabel: 'Remove permanently',
        message: (name) => `Remove ${name} from this workspace? This cannot be undone.`,
    },
    role: {
        title: 'Change role?',
        tone: 'warning',
        confirmLabel: 'Update role',
        message: (name, form) => {
            const nextRole = form?.dataset?.nextRole === 'admin' ? 'administrator' : 'agent';

            return `Change ${name}'s role to ${nextRole}?`;
        },
    },
};

let pendingForm = null;

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function adminOptions() {
    const root = document.getElementById('workspace-member-management');
    if (!root) {
        return null;
    }

    return {
        workspaceId: root.dataset.workspaceId,
        membersBase: root.dataset.membersBase,
        csrf: root.dataset.csrfToken || '',
    };
}

function getModal() {
    return document.getElementById('member-confirm-modal');
}

function setModalIcon(tone) {
    const icon = document.getElementById('member-confirm-icon');
    if (!icon) {
        return;
    }

    icon.className = `member-confirm-icon member-confirm-icon-${tone}`;
    icon.innerHTML = tone === 'error'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20 10 10 0 000-20z"/></svg>';
}

function openConfirmModal(form) {
    const modal = getModal();
    if (!modal) {
        submitMemberForm(form);

        return;
    }

    const action = form.dataset.memberAction;
    const copy = ACTION_COPY[action];
    if (!copy) {
        submitMemberForm(form);

        return;
    }

    const name = form.dataset.memberName || 'this member';
    pendingForm = form;

    document.getElementById('member-confirm-title').textContent = copy.title;
    document.getElementById('member-confirm-message').textContent = copy.message(name, form);
    document.getElementById('member-confirm-submit').textContent = copy.confirmLabel;
    setModalIcon(copy.tone);

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('member-confirm-open');
}

function closeConfirmModal() {
    const modal = getModal();
    if (!modal) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('member-confirm-open');
    pendingForm = null;
}

function flashMemberRow(form) {
    const row = form.closest('.member-row');
    if (!row) {
        return;
    }

    row.classList.remove('member-row-flash');
    void row.offsetWidth;
    row.classList.add('member-row-flash');
}

async function submitMemberForm(form) {
    const name = form.dataset.memberName || 'Member';
    const row = form.closest('.member-row');
    const method = (form.querySelector('[name="_method"]')?.value || form.method || 'POST').toUpperCase();

    if (row) {
        row.classList.add('member-row-busy');
    }

    flashMemberRow(form);

    try {
        const response = await fetch(form.action, {
            method: method === 'GET' ? 'POST' : method,
            body: new FormData(form),
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message = payload.message
                || Object.values(payload.errors || {}).flat().join(' ')
                || `Could not update ${name}.`;
            showToast(message, 'error');
            row?.classList.remove('member-row-busy');
            return;
        }

        showToast(payload.message || `${name} updated.`, 'success');
    } catch {
        showToast(`Network error while updating ${name}.`, 'error');
        row?.classList.remove('member-row-busy');
    }
}

function bindMemberForms() {
    document.querySelectorAll('form[data-member-action]').forEach((form) => {
        if (form.dataset.memberBound === '1') {
            return;
        }

        form.dataset.memberBound = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (form.dataset.confirmed === '1') {
                form.dataset.confirmed = '0';
                submitMemberForm(form);

                return;
            }

            openConfirmModal(form);
        });
    });
}

function bindConfirmModal() {
    const modal = getModal();
    if (!modal || modal.dataset.bound === '1') {
        return;
    }

    modal.dataset.bound = '1';

    modal.querySelectorAll('[data-member-confirm-dismiss]').forEach((element) => {
        element.addEventListener('click', closeConfirmModal);
    });

    document.getElementById('member-confirm-submit')?.addEventListener('click', () => {
        if (!pendingForm) {
            closeConfirmModal();
            return;
        }

        pendingForm.dataset.confirmed = '1';
        const form = pendingForm;
        closeConfirmModal();
        submitMemberForm(form);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeConfirmModal();
        }
    });
}

export function renderAdminMemberRow(member, options) {
    const status = member.status || 'active';
    const role = member.role || 'marketer';
    const nextRole = role === 'admin' ? 'marketer' : 'admin';
    const base = options.membersBase;
    const csrf = options.csrf;
    const canManage = member.can_manage;

    const actions = canManage
        ? `<div class="member-row-actions flex flex-wrap items-center gap-2">
                <form method="POST" action="${escapeHtml(base)}/${member.id}/role" data-member-action="role" data-member-name="${escapeHtml(member.name)}" data-next-role="${nextRole}">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <input type="hidden" name="_method" value="PATCH">
                    <input type="hidden" name="role" value="${nextRole}">
                    <button type="submit" class="member-action-btn member-action-btn-role">${role === 'admin' ? 'Make agent' : 'Make admin'}</button>
                </form>
                <form method="POST" action="${escapeHtml(base)}/${member.id}/suspend" data-member-action="suspend" data-member-name="${escapeHtml(member.name)}" ${status === 'suspended' ? 'hidden' : ''}>
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="member-action-btn member-action-btn-suspend">Suspend</button>
                </form>
                <form method="POST" action="${escapeHtml(base)}/${member.id}/reactivate" data-member-action="reactivate" data-member-name="${escapeHtml(member.name)}" ${status !== 'suspended' ? 'hidden' : ''}>
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="member-action-btn member-action-btn-reactivate">Reactivate</button>
                </form>
                <form method="POST" action="${escapeHtml(base)}/${member.id}" data-member-action="remove" data-member-name="${escapeHtml(member.name)}">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" class="member-action-btn member-action-btn-remove">Remove</button>
                </form>
           </div>`
        : '';

    return `
        <div class="member-row py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 ${status === 'suspended' ? 'member-row-suspended' : ''}" data-member-id="${member.id}">
            <div class="member-row-identity min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-bold text-slate-800 text-sm">${escapeHtml(member.name)}</span>
                    ${member.is_owner ? '<span class="member-owner-badge">Owner</span>' : ''}
                    <span class="member-status-badge member-status-${status}" data-member-status>${status === 'suspended' ? 'Suspended' : (status === 'invited' ? 'Invited' : 'Active')}</span>
                </div>
                <div class="text-xs text-slate-400 mt-1 truncate">${escapeHtml(member.email)}</div>
                <div class="text-xs text-slate-500 mt-0.5" data-member-role>${role === 'marketer' ? 'Agent' : 'Administrator'}</div>
            </div>
            ${actions}
        </div>
    `;
}

export function replaceAdminTeam(container, members) {
    const options = adminOptions();
    if (!container || !options || !Array.isArray(members)) {
        return;
    }

    const html = members.map((member) => renderAdminMemberRow(member, options)).join('');

    container.classList.add('live-sync-updating');
    window.requestAnimationFrame(() => {
        container.innerHTML = html;
        bindMemberForms();
        window.requestAnimationFrame(() => container.classList.remove('live-sync-updating'));
    });
}

export function initMemberManagement() {
    if (!document.getElementById('workspace-member-management')) {
        return;
    }

    bindConfirmModal();
    bindMemberForms();
}

export function updateMemberRows(container, members) {
    if (!container || !Array.isArray(members)) {
        return;
    }

    if (container.dataset.adminTeam === '1') {
        replaceAdminTeam(container, members);
        return;
    }

    const memberIds = new Set(members.map((member) => member.id));

    container.querySelectorAll('[data-member-id]').forEach((row) => {
        const id = Number(row.dataset.memberId);
        if (!memberIds.has(id)) {
            row.classList.add('member-row-removing');
            window.setTimeout(() => row.remove(), 320);
        }
    });

    members.forEach((member) => {
        const row = container.querySelector(`[data-member-id="${member.id}"]`);
        if (!row) {
            return;
        }

        const status = member.status || 'active';
        const badge = row.querySelector('[data-member-status]');
        if (badge) {
            badge.className = `member-status-badge member-status-${status}`;
            badge.textContent = status === 'suspended' ? 'Suspended' : (status === 'invited' ? 'Invited' : 'Active');
        }

        const roleEl = row.querySelector('[data-member-role]');
        if (roleEl) {
            roleEl.textContent = member.role === 'marketer' ? 'Agent' : 'Administrator';
        }

        const suspendForm = row.querySelector('[data-member-action="suspend"]');
        const reactivateForm = row.querySelector('[data-member-action="reactivate"]');
        if (suspendForm) {
            suspendForm.hidden = status === 'suspended';
        }
        if (reactivateForm) {
            reactivateForm.hidden = status !== 'suspended';
        }

        row.classList.toggle('member-row-suspended', status === 'suspended');
        row.classList.remove('member-row-busy');
    });
}
