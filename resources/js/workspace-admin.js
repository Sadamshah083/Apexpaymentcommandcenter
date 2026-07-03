import { bindModuleAccessFormFields, syncModuleAccessForRole } from './member-management.js';

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
    const root = adminRoot();
    if (!root) {
        return;
    }

    const createForm = root.querySelector('[data-workspace-create-member]');
    if (!createForm || createForm.dataset.bound === '1') {
        return;
    }

    createForm.dataset.bound = '1';
    createForm.addEventListener('submit', async (event) => {
        if (createForm.dataset.submitting === '1') {
            event.preventDefault();
            return;
        }

        if (!createForm.checkValidity()) {
            return;
        }

        event.preventDefault();
        createForm.dataset.submitting = '1';

        const submitButton = createForm.querySelector('button[type="submit"]');
        const originalText = submitButton?.textContent;
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Creating…';
        }

        try {
            const response = await fetch(createForm.action, {
                method: 'POST',
                body: new FormData(createForm),
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
            window.showToast?.(payload.message || 'Agent account created.', 'success');
            window.setTimeout(() => window.location.reload(), 600);
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
    const root = adminRoot();
    if (!root) {
        return;
    }

    const roleSelect = root.querySelector('[data-create-member-role]');
    const modulesPanel = root.querySelector('[data-create-member-modules]');
    if (!roleSelect || !modulesPanel) {
        return;
    }

    const configurableRoles = new Set([
        'admin',
        'manager',
        'appointment_setter_team_lead',
        'closers_team_lead',
        'appointment_setter',
        'closer',
    ]);

    const syncVisibility = () => {
        const role = roleSelect.value;
        modulesPanel.classList.toggle('hidden', !configurableRoles.has(role));
        syncModuleAccessForRole(modulesPanel, role);
    };

    roleSelect.addEventListener('change', syncVisibility);
    syncVisibility();

    bindModuleAccessFormFields(modulesPanel);
}

export function initWorkspaceAdmin() {
    if (!adminRoot()) {
        return;
    }

    bindWorkspaceSwitchForms();
    bindCreateMemberForm();
    bindCreateMemberRoleToggle();
}
