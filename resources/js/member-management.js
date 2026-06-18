import { showToast } from './toast.js';
import { showLoadingOverlay } from './form-loading.js';

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
        form.dataset.confirmed = '1';
        form.requestSubmit();

        return;
    }

    const action = form.dataset.memberAction;
    const copy = ACTION_COPY[action];
    if (!copy) {
        form.dataset.confirmed = '1';
        form.requestSubmit();

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

function bindMemberForms() {
    document.querySelectorAll('form[data-member-action]').forEach((form) => {
        if (form.dataset.memberBound === '1') {
            return;
        }

        form.dataset.memberBound = '1';

        form.addEventListener('submit', (event) => {
            if (form.dataset.confirmed === '1') {
                form.dataset.confirmed = '0';
                const action = form.dataset.memberAction;
                const name = form.dataset.memberName || 'Member';
                const row = form.closest('.member-row');

                if (row) {
                    row.classList.add('member-row-busy');
                }

                showLoadingOverlay(`Updating ${name}…`, 'Account management');
                flashMemberRow(form);

                return;
            }

            event.preventDefault();
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
        form.requestSubmit();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeConfirmModal();
        }
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
