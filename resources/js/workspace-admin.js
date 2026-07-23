import { bindModuleAccessFormFields, syncModuleAccessForRole } from './member-management.js';
import { refreshPrettySelect } from './pretty-select.js';

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function adminRoot() {
    return document.getElementById('workspace-member-management');
}

function updateText(id, value) {
    const el = document.getElementById(id);
    if (el && el.textContent !== String(value ?? '')) {
        el.textContent = String(value ?? '');
        el.classList.add('live-sync-flash');
        window.setTimeout(() => el.classList.remove('live-sync-flash'), 320);
    }
}

function closeAllUmModals() {
    document.querySelectorAll('.member-confirm-modal').forEach((modal) => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
    });
    document.body.classList.remove('member-confirm-open');
}

function openUmModal(modal, onOpen) {
    if (!modal) {
        return;
    }

    closeAllUmModals();
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('member-confirm-open');
    onOpen?.(modal);
}

function closeUmModal(modal) {
    if (!modal) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');

    const anyOpen = Array.from(document.querySelectorAll('.member-confirm-modal')).some(
        (entry) => !entry.hidden,
    );
    if (!anyOpen) {
        document.body.classList.remove('member-confirm-open');
    }
}

function bindUmModal({ modalId, openSelector, dismissSelector, onOpen }) {
    const modal = document.getElementById(modalId);
    if (!modal || modal.dataset.bound === '1') {
        return modal;
    }

    modal.dataset.bound = '1';

    document.querySelectorAll(openSelector).forEach((button) => {
        button.addEventListener('click', (event) => {
            event.preventDefault();
            openUmModal(modal, onOpen);
        });
    });

    modal.querySelectorAll(dismissSelector).forEach((element) => {
        element.addEventListener('click', () => closeUmModal(modal));
    });

    return modal;
}

function bindUmModals() {
    if (document.body.dataset.umModalsBound === '1') {
        return;
    }

    document.body.dataset.umModalsBound = '1';

    const addMemberModal = bindUmModal({
        modalId: 'um-add-member-modal',
        openSelector: '[data-um-add-member-open]',
        dismissSelector: '[data-um-add-member-dismiss]',
        onOpen: (modal) => {
            const roleSelect = modal.querySelector('[data-create-member-role]');
            const modulesPanel = modal.querySelector('[data-create-member-modules]');
            if (roleSelect && modulesPanel) {
                const role = roleSelect.value;
                const configurableRoles = new Set([
                    'admin',
                    'manager',
                    'appointment_setter_team_lead',
                    'closers_team_lead',
                    'appointment_setter',
                    'closer',
                ]);
                modulesPanel.classList.toggle('hidden', !configurableRoles.has(role));
                syncModuleAccessForRole(modulesPanel, role);
            }

            modal.querySelector('#create-username')?.focus();
        },
    });

    if (addMemberModal?.querySelector('.um-alert-error')) {
        openUmModal(addMemberModal, () => {
            addMemberModal.querySelector('#create-username')?.focus();
        });
    }

    bindUmModal({
        modalId: 'um-portal-info-modal',
        openSelector: '[data-um-portal-info-open]',
        dismissSelector: '[data-um-portal-info-dismiss]',
    });

    bindUmModal({
        modalId: 'um-create-workspace-modal',
        openSelector: '[data-um-create-workspace-open]',
        dismissSelector: '[data-um-create-workspace-dismiss]',
        onOpen: (modal) => {
            modal.querySelector('#create-workspace-name')?.focus();
        },
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        const openModal = Array.from(document.querySelectorAll('.member-confirm-modal')).find(
            (modal) => !modal.hidden,
        );
        if (openModal) {
            closeUmModal(openModal);
        }
    });
}

export function applyWorkspaceAdminState(data) {
    const root = adminRoot();
    if (!root || !data) {
        return;
    }

    const context = data.workspace_context;
    if (context) {
        updateText('workspace-active-name', context.name);
        updateText('workspace-active-owner', context.admin_name ?? '—');
        updateText('workspace-active-owner-email', context.admin_email ? `(${context.admin_email})` : '');
        updateText('workspace-stat-workflows', String(context.workflow_count ?? 0));
        updateText('workspace-stat-members', String(context.member_count ?? 0));
    }

    if (!Array.isArray(data.workspaces)) {
        return;
    }

    const list = document.getElementById('workspace-sync-contexts');
    if (!list) {
        return;
    }

    const switchBase = root.dataset.workspaceSwitchBase || '';
    const csrf = root.dataset.csrfToken || '';

    const html = data.workspaces
        .map((workspace) => {
            const active = workspace.is_active;
            const switchForm =
                !active && switchBase
                    ? `<form method="POST" action="${escapeHtml(switchBase)}/${workspace.id}" class="workspace-switch-form">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <button type="submit" class="um-btn um-btn-ghost um-btn-sm">Switch</button>
               </form>`
                    : '<span class="um-badge um-badge-active">Active</span>';

            return `
            <div class="um-workspace-card ${active ? 'um-workspace-card-active' : ''}" data-workspace-id="${workspace.id}">
                <div class="um-workspace-card-body">
                    <h4 class="um-workspace-name">${escapeHtml(workspace.name)}</h4>
                    <p class="um-workspace-meta">Owner: ${escapeHtml(workspace.admin_name ?? '—')}</p>
                    <p class="um-workspace-stats">${workspace.workflow_count ?? 0} pipelines · ${workspace.member_count ?? 0} members</p>
                </div>
                ${switchForm}
            </div>
        `;
        })
        .join('');

    list.classList.add('live-sync-updating');
    window.requestAnimationFrame(() => {
        list.innerHTML = html;
        window.requestAnimationFrame(() => list.classList.remove('live-sync-updating'));
    });
}

function bindWorkspaceSwitchForms() {
    const root = adminRoot();
    if (!root || root.dataset.switchBound === '1') {
        return;
    }

    root.dataset.switchBound = '1';

    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('.workspace-switch-form');
        if (!form) {
            return;
        }

        event.preventDefault();

        const button = form.querySelector('button[type="submit"]');
        if (button) {
            button.disabled = true;
            button.textContent = 'Switching…';
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

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                window.showToast?.(payload.message || 'Could not switch workspace.', 'error');
                return;
            }

            window.showToast?.(payload.message || 'Workspace switched.', 'success');
            window.location.reload();
        } catch {
            window.showToast?.('Network error while switching workspace.', 'error');
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = 'Switch';
            }
        }
    });
}

function bindCreateMemberForm() {
    const createForm = document.querySelector('[data-workspace-create-member]');
    if (!createForm || createForm.dataset.bound === '1') {
        return;
    }

    createForm.dataset.bound = '1';

    const modal = document.getElementById('um-add-member-modal');
    const emailDomain = String(modal?.dataset.emailDomain || 'apexonepayments.com').toLowerCase();
    const usernameInput = createForm.querySelector('[data-create-member-username], #create-username');
    const emailLocalInput = createForm.querySelector('[data-create-member-email-local]');
    const emailHidden = createForm.querySelector('[data-create-member-email], #create-email');
    const extensionSelect = createForm.querySelector('[data-create-member-extension], #create-extension');
    const didSelect = createForm.querySelector('[data-create-member-did], #create-did');

    const syncEmail = () => {
        let localRaw = String(emailLocalInput?.value || usernameInput?.value || '').trim().toLowerCase();
        // If the user pasted a full email into the local box, keep only the local part.
        if (localRaw.includes('@')) {
            localRaw = localRaw.split('@')[0] || '';
        }
        const local = localRaw.replace(/[^a-z0-9._+-]/g, '') || 'agent';
        if (emailLocalInput && emailLocalInput.value !== local && document.activeElement !== emailLocalInput) {
            emailLocalInput.value = local;
        }
        if (emailHidden) {
            emailHidden.value = `${local}@${emailDomain}`;
        }
        return emailHidden?.value || `${local}@${emailDomain}`;
    };

    const syncExtensionFromDid = () => {
        if (!didSelect || !extensionSelect) return;
        const option = didSelect.selectedOptions?.[0];
        const suggested = option?.dataset?.extension || '';
        if (suggested && (!extensionSelect.value || extensionSelect.dataset.autofilled === '1')) {
            extensionSelect.value = suggested;
            extensionSelect.dataset.autofilled = '1';
        }
    };

    usernameInput?.addEventListener('input', () => {
        if (emailLocalInput && !emailLocalInput.dataset.touched) {
            emailLocalInput.value = String(usernameInput.value || '')
                .trim()
                .toLowerCase()
                .replace(/[^a-z0-9._+-]/g, '');
        }
        syncEmail();
    });
    emailLocalInput?.addEventListener('input', () => {
        emailLocalInput.dataset.touched = '1';
        syncEmail();
    });
    extensionSelect?.addEventListener('input', () => {
        extensionSelect.dataset.autofilled = '0';
    });
    didSelect?.addEventListener('change', syncExtensionFromDid);
    syncEmail();
    syncExtensionFromDid();

    createForm.addEventListener('submit', async (event) => {
        if (createForm.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }

        syncEmail();
        syncExtensionFromDid();

        if (!createForm.checkValidity()) {
            return;
        }

        event.preventDefault();

        const email = String(emailHidden?.value || '').trim().toLowerCase();
        if (!email.endsWith(`@${emailDomain}`)) {
            window.showToast?.(`Email must use @${emailDomain}.`, 'error');
            emailLocalInput?.focus();
            return;
        }

        createForm.dataset.submitting = '1';

        const submitButton = createForm.querySelector('button[type="submit"]');
        const originalText = submitButton?.textContent;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Creating…';
        }

        try {
            const formData = new FormData(createForm);
            const finalEmail = syncEmail();
            formData.set('email', finalEmail);

            // Disabled selects are omitted from FormData — re-enable team lead for submit.
            const teamLeadSelect = createForm.querySelector('[data-create-member-team-lead]');
            if (teamLeadSelect && teamLeadSelect.disabled && teamLeadSelect.value) {
                formData.set('team_lead_user_id', teamLeadSelect.value);
            }

            const response = await fetch(createForm.action, {
                method: 'POST',
                body: formData,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message =
                    payload.message ||
                    Object.values(payload.errors || {})
                        .flat()
                        .join(' ') ||
                    'Could not create agent account.';
                window.showToast?.(message, 'error');
                return;
            }

            createForm.reset();
            if (emailLocalInput) delete emailLocalInput.dataset.touched;
            closeUmModal(document.getElementById('um-add-member-modal'));
            window.showToast?.(payload.message || 'Agent account created.', 'success');
            // Hard reload so the new row always appears in User Management.
            window.setTimeout(() => {
                const url = new URL(window.location.href);
                url.searchParams.delete('page');
                if (payload.member?.id) {
                    url.searchParams.set('highlight', String(payload.member.id));
                }
                window.location.assign(url.toString());
            }, 400);
        } catch {
            window.showToast?.('Network error while creating account.', 'error');
        } finally {
            createForm.dataset.submitting = '0';
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalText || 'Create account';
            }
        }
    });
}

function bindCreateMemberRoleToggle() {
    const roleSelect = document.querySelector('[data-create-member-role]');
    const modulesPanel = document.querySelector('[data-create-member-modules]');
    if (!roleSelect || roleSelect.dataset.bound === '1') {
        return;
    }

    roleSelect.dataset.bound = '1';

    const configurableRoles = new Set([
        'admin',
        'manager',
        'appointment_setter_team_lead',
        'closers_team_lead',
        'appointment_setter',
        'closer',
        'closers_qa',
    ]);

    const parseLeadJson = (raw) => {
        try {
            const parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch {
            return [];
        }
    };

    const fillCreateTeamLeadOptions = (select, role) => {
        if (!select) {
            return;
        }

        const modal = document.getElementById('um-add-member-modal');
        const setterLeads = parseLeadJson(modal?.dataset.setterLeads);
        const closerLeads = parseLeadJson(modal?.dataset.closerLeads);
        const leads =
            role === 'closer' || role === 'closers_qa' ? closerLeads : setterLeads;

        select.innerHTML = '<option value="">Unassigned</option>';
        leads.forEach((lead) => {
            const option = document.createElement('option');
            option.value = String(lead.id);
            option.textContent = lead.campaign_name
                ? `${lead.name} · ${lead.campaign_name}`
                : lead.name;
            select.appendChild(option);
        });

        if (leads.length > 0 && (role === 'closer' || role === 'appointment_setter')) {
            select.value = String(leads[0].id);
        }
    };

    const syncCreateAssignmentFields = () => {
        const role = roleSelect.value;
        const campaignField = document.querySelector('[data-create-campaign-field]');
        const teamLeadField = document.querySelector('[data-create-team-lead-field]');
        const campaignSelect = document.querySelector('[data-create-member-campaign]');
        const teamLeadSelect = document.querySelector('[data-create-member-team-lead]');
        const isTeamLead = role === 'appointment_setter_team_lead' || role === 'closers_team_lead';
        const isAgent = role === 'appointment_setter' || role === 'closer';

        if (campaignField) {
            campaignField.hidden = !isTeamLead;
        }
        if (teamLeadField) {
            teamLeadField.hidden = !isAgent;
        }

        if (campaignSelect) {
            if (isTeamLead) {
                campaignSelect.disabled = false;
                campaignSelect.removeAttribute('disabled');
            } else {
                campaignSelect.value = '';
                campaignSelect.disabled = true;
            }
        }

        if (teamLeadSelect) {
            if (isAgent) {
                teamLeadSelect.disabled = false;
                teamLeadSelect.removeAttribute('disabled');
                fillCreateTeamLeadOptions(teamLeadSelect, role);
            } else {
                teamLeadSelect.innerHTML = '<option value="">Unassigned</option>';
                teamLeadSelect.value = '';
                teamLeadSelect.disabled = true;
            }
        }

        [campaignSelect, teamLeadSelect, roleSelect].forEach((select) => {
            if (select) {
                refreshPrettySelect(select);
            }
        });
    };

    const syncVisibility = () => {
        const role = roleSelect.value;
        if (modulesPanel) {
            modulesPanel.classList.toggle('hidden', !configurableRoles.has(role));
            syncModuleAccessForRole(modulesPanel, role);
        }
        syncCreateAssignmentFields();
    };

    roleSelect.addEventListener('change', syncVisibility);
    syncVisibility();

    if (modulesPanel) {
        bindModuleAccessFormFields(modulesPanel);
    }
}

export function initWorkspaceAdmin() {
    if (!adminRoot()) {
        return;
    }

    bindUmModals();
    bindWorkspaceSwitchForms();
    bindCreateMemberForm();
    bindCreateMemberRoleToggle();
}
