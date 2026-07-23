import { showToast } from './toast.js';
import { refreshPrettySelect } from './pretty-select.js';

const ACTION_COPY = {
    suspend: {
        title: 'Suspend account?',
        tone: 'warning',
        confirmLabel: 'Suspend',
        message: (name) =>
            `Are you sure you want to suspend ${name}? They will immediately lose portal access.`,
    },
    reactivate: {
        title: 'Reactivate account?',
        tone: 'success',
        confirmLabel: 'Reactivate account',
        message: (name) => `${name} will be able to sign in to the agent portal again.`,
    },
    remove: {
        title: 'Delete account?',
        tone: 'error',
        confirmLabel: 'Delete permanently',
        message: (name) =>
            `Are you sure you want to permanently delete ${name}? This cannot be undone. Their campaign and team assignments will be removed.`,
    },
    role: {
        title: 'Change role?',
        tone: 'warning',
        confirmLabel: 'Switch role',
        message: (name, form) => {
            const select = form?.querySelector('[name="role"], [data-member-role-select]');
            const nextValue = form?.dataset.pendingRole || select?.value || '';
            const previousValue = form?.dataset.originalRole || '';
            const label =
                form?.dataset.pendingRoleLabel ||
                select?.selectedOptions?.[0]?.textContent?.trim() ||
                'the new role';

            const fronterRoles = ['appointment_setter', 'appointment_setter_team_lead'];
            const closerRoles = ['closer', 'closers_team_lead', 'closers_qa'];
            const crossingFamily =
                (fronterRoles.includes(previousValue) && closerRoles.includes(nextValue))
                || (closerRoles.includes(previousValue) && fronterRoles.includes(nextValue));

            if (crossingFamily) {
                if (fronterRoles.includes(previousValue) && closerRoles.includes(nextValue)) {
                    return `Switch ${name} to ${label}? A B2B Closer Team Lead must exist first — they will be assigned under the first closer team lead automatically.`;
                }

                return `Switch ${name} from their current team family to ${label}? Team lead and campaign assignments for the old family will be cleared.`;
            }

            return `Change ${name}'s role to ${label}?`;
        },
    },
    'team-lead': {
        title: 'Assign team lead?',
        tone: 'warning',
        confirmLabel: 'Save team lead',
        message: (name, form) => {
            const select = form?.querySelector('[name="team_lead_user_id"], [data-member-team-select]');
            const label = select?.selectedOptions?.[0]?.textContent?.trim() || '';
            if (!select?.value) {
                return `Remove ${name} from their team lead?`;
            }

            return `Assign ${name} under ${label.trim()}? They will inherit that lead's campaign only.`;
        },
    },
    campaign: {
        title: 'Assign campaign?',
        tone: 'warning',
        confirmLabel: 'Save campaign',
        message: (name, form) => {
            const select = form?.querySelector('[name="campaign_id"], [data-member-campaign-select]');
            const label = select?.selectedOptions?.[0]?.textContent?.trim() || '';
            if (!select?.value) {
                return `Remove campaign from ${name}? Their team members will also lose it.`;
            }

            return `Assign "${label.trim()}" to team lead ${name}? Only their team members inherit this campaign.`;
        },
    },
    'update': {
        title: 'Save account changes?',
        tone: 'warning',
        confirmLabel: 'Save changes',
        message: (name, form) => {
            const roleLabel = form?.querySelector('[data-edit-member-role] option:checked')?.textContent?.trim()
                || form?.querySelector('[name="role"] option:checked')?.textContent?.trim()
                || '';
            const campaign = form?.querySelector('[data-edit-member-campaign]')?.selectedOptions?.[0]?.textContent?.trim();
            const teamLead = form?.querySelector('[data-edit-member-team-lead]')?.selectedOptions?.[0]?.textContent?.trim();
            const bits = [`Save account changes for ${name}?`];
            if (roleLabel) {
                bits.push(`Role: ${roleLabel}.`);
            }
            if (campaign && campaign !== 'Unassigned') {
                bits.push(`Campaign: ${campaign}.`);
            }
            if (teamLead && teamLead !== 'Unassigned') {
                bits.push(`Team lead: ${teamLead}.`);
            }

            return bits.join(' ');
        },
    },
    'reset-password': {
        title: 'Reset password?',
        tone: 'warning',
        confirmLabel: 'Update password',
        message: (name) => `Set a new password for ${name}?`,
    },
    modules: {
        title: 'Update module access?',
        tone: 'warning',
        confirmLabel: 'Save module access',
        message: (name) => `Update which admin features ${name} can access?`,
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

    let roleLabels = {};
    try {
        roleLabels = JSON.parse(root.dataset.roleLabels || '{}');
    } catch {
        roleLabels = {};
    }

    return {
        workspaceId: root.dataset.workspaceId,
        membersBase: root.dataset.membersBase,
        csrf: root.dataset.csrfToken || '',
        roleLabels,
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
    icon.innerHTML =
        tone === 'error'
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

    if (action === 'role') {
        const select = form.querySelector('[name="role"], [data-member-role-select]');
        if (select?.value) {
            if (!form.dataset.originalRole) {
                form.dataset.originalRole = select.dataset.initialRole || select.value;
            }
            form.dataset.pendingRole = form.dataset.pendingRole || select.value;
            form.dataset.pendingRoleLabel =
                form.dataset.pendingRoleLabel ||
                select.selectedOptions?.[0]?.textContent?.trim() ||
                select.value;
        }
    }

    const name = form.dataset.memberName || 'this member';
    pendingForm = form;

    document.getElementById('member-confirm-title').textContent = copy.title;
    document.getElementById('member-confirm-message').textContent = copy.message(name, form);
    const submitBtn = document.getElementById('member-confirm-submit');
    if (submitBtn) {
        submitBtn.textContent = copy.confirmLabel;
        submitBtn.classList.toggle('member-confirm-submit--danger', copy.tone === 'error');
        submitBtn.classList.toggle('member-confirm-submit--warning', copy.tone === 'warning');
    }
    setModalIcon(copy.tone);

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('member-confirm-open');
}

function closeConfirmModal({ revertRole = true } = {}) {
    const modal = getModal();
    if (!modal) {
        return;
    }

    // If user cancels a role change, restore the previously saved role on the row.
    if (revertRole && pendingForm?.dataset.memberAction === 'role') {
        const select = pendingForm.querySelector('[name="role"], [data-member-role-select]');
        const original = pendingForm.dataset.originalRole;
        if (select && original) {
            select.value = original;
            Array.from(select.options).forEach((option) => {
                option.selected = option.value === original;
            });
            syncRoleDropdownFromSelect(select);
        }
        delete pendingForm.dataset.pendingRole;
        delete pendingForm.dataset.pendingRoleLabel;
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
    const methodInput = form.querySelector('[name="_method"]');

    let formData;
    try {
        formData = prepareMemberFormData(form);
    } catch (error) {
        showToast(error?.message || `Could not update ${name}.`, 'error');
        return;
    }

    // Always POST when method-spoofing so role/password updates hit Laravel reliably.
    const fetchMethod = methodInput ? 'POST' : (form.method || 'POST').toUpperCase();

    if (form.dataset.memberAction === 'role') {
        const select = form.querySelector('[name="role"], [data-member-role-select]');
        const roleValue = form.dataset.pendingRole || select?.value || '';
        if (roleValue) {
            formData.set('role', roleValue);
            if (select) {
                select.value = roleValue;
                Array.from(select.options).forEach((option) => {
                    option.selected = option.value === roleValue;
                });
            }
        }
    }

    if (row) {
        row.classList.add('member-row-busy');
    }

    flashMemberRow(form);

    const submitButtons = form.querySelectorAll('button[type="submit"]');
    submitButtons.forEach((button) => {
        button.disabled = true;
        button.dataset.originalLabel = button.textContent || '';
        if (form.dataset.memberAction === 'update' || form.dataset.memberAction === 'reset-password') {
            button.textContent = 'Saving…';
        }
    });

    try {
        const response = await fetch(form.action, {
            method: fetchMethod === 'GET' ? 'POST' : fetchMethod,
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
                `Could not update ${name}.`;
            showToast(message, 'error');
            row?.classList.remove('member-row-busy');
            submitButtons.forEach((button) => {
                button.disabled = false;
                if (button.dataset.originalLabel) {
                    button.textContent = button.dataset.originalLabel;
                }
            });

            return;
        }

        if (form.dataset.memberAction === 'role') {
            const select = form.querySelector('[name="role"], [data-member-role-select]');
            if (select) {
                select.dataset.initialRole = form.dataset.pendingRole || select.value;
            }
            delete form.dataset.pendingRole;
            delete form.dataset.pendingRoleLabel;
            delete form.dataset.originalRole;
            showToast(payload.message || `${name} updated.`, 'success');
            window.setTimeout(() => window.location.reload(), 500);
            row?.classList.remove('member-row-busy');
            return;
        }

        if (form.dataset.memberAction === 'team-lead') {
            showToast(payload.message || `${name} team updated.`, 'success');
            window.setTimeout(() => window.location.reload(), 400);
            row?.classList.remove('member-row-busy');
            return;
        }

        if (form.dataset.memberAction === 'campaign') {
            showToast(payload.message || `${name} campaign updated.`, 'success');
            window.setTimeout(() => window.location.reload(), 400);
            row?.classList.remove('member-row-busy');
            return;
        }

        if (form.dataset.memberAction === 'suspend' || form.dataset.memberAction === 'reactivate') {
            const status = form.dataset.memberAction === 'suspend' ? 'suspended' : 'active';
            const badge = row?.querySelector('[data-member-status]');
            if (badge) {
                badge.className = `member-status-badge member-status-${status} um-badge um-badge-status-${status}`;
                badge.textContent = status === 'suspended' ? 'Suspended' : 'Active';
            }
            row?.querySelector('[data-member-action="suspend"]')?.toggleAttribute('hidden', status === 'suspended');
            row?.querySelector('[data-member-action="reactivate"]')?.toggleAttribute('hidden', status !== 'suspended');
            row?.classList.toggle('member-row-suspended', status === 'suspended');
            row?.classList.toggle('um-member-card-suspended', status === 'suspended');
        }

        if (form.dataset.memberAction === 'remove') {
            row?.classList.add('member-row-removing');
            window.setTimeout(() => {
                row?.remove();
                const list = document.getElementById('workspace-sync-team');
                if (list && list.querySelectorAll('.member-row').length === 0) {
                    list.innerHTML = `
                        <tr data-um-empty-members>
                            <td colspan="8">
                                <div class="um-empty-state">
                                    <p class="um-empty-title">No team members yet</p>
                                    <p class="um-empty-desc">Create an agent account below to get started.</p>
                                </div>
                            </td>
                        </tr>
                    `;
                }
            }, 320);
        }

        if (form.dataset.memberAction === 'modules') {
            const summary = row?.querySelector('[data-member-module-summary]');
            const restricted = form.querySelector('[data-module-access-mode]')?.value === 'restricted';
            const checkedCount = form.querySelectorAll('.um-module-tick-item:not(.hidden) [data-module-option]:checked').length;
            if (summary) {
                summary.textContent = restricted ? `${checkedCount} module(s)` : 'Full admin access';
                summary.classList.remove('hidden');
            }

            closeModuleAccessModal();
        }

        if (form.dataset.memberAction === 'update') {
            showToast(payload.message || `${name} updated.`, 'success');
            closeAllMemberModals();
            window.setTimeout(() => window.location.reload(), 350);
            row?.classList.remove('member-row-busy');
            return;
        }

        if (form.dataset.memberAction === 'reset-password') {
            form.reset();
            showToast(payload.message || `Password updated for ${name}.`, 'success');
            closeAllMemberModals();
            window.setTimeout(() => window.location.reload(), 350);
            row?.classList.remove('member-row-busy');
            return;
        }

        showToast(payload.message || `${name} updated.`, 'success');
        row?.classList.remove('member-row-busy');
        submitButtons.forEach((button) => {
            button.disabled = false;
            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
            }
        });
    } catch {
        showToast(`Network error while updating ${name}.`, 'error');
        row?.classList.remove('member-row-busy');
        submitButtons.forEach((button) => {
            button.disabled = false;
            if (button.dataset.originalLabel) {
                button.textContent = button.dataset.originalLabel;
            }
        });
    }
}

function syncAccessModeDropdownFromSelect(select) {
    const wrapper = select?.closest('[data-access-mode-dropdown]');
    if (!wrapper) {
        return;
    }

    const label = wrapper.querySelector('.um-role-dropdown-label');
    const option = select.selectedOptions?.[0];
    if (label && option) {
        label.textContent = option.textContent.trim();
    }

    wrapper.querySelectorAll('[data-access-mode-option]').forEach((button) => {
        const selected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
}

function closeAccessModeDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.um-role-dropdown-trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper.querySelector('.um-role-dropdown-menu');
    if (menu) {
        menu.hidden = true;
    }
}

function closeAllAccessModeDropdowns(except = null) {
    document.querySelectorAll('[data-access-mode-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeAccessModeDropdown(wrapper);
        }
    });
}

function syncModulePickerLabel(picker) {
    if (!picker) {
        return;
    }

    const label = picker.querySelector('.um-module-picker-label');
    if (!label) {
        return;
    }

    const checked = [...picker.querySelectorAll('[data-module-option]:checked')];
    if (checked.length === 0) {
        label.textContent = 'Select modules…';

        return;
    }

    if (checked.length === 1) {
        const text =
            checked[0]
                .closest('.um-module-picker-option')
                ?.querySelector('.um-module-check-label')
                ?.textContent?.trim() || '1 module selected';
        label.textContent = text;

        return;
    }

    label.textContent = `${checked.length} modules selected`;
}

function closeModulePicker(picker) {
    if (!picker) {
        return;
    }

    picker.classList.remove('is-open');
    picker.querySelector('.um-module-picker-trigger')?.setAttribute('aria-expanded', 'false');
    const menu = picker.querySelector('.um-module-picker-menu');
    if (menu) {
        menu.hidden = true;
    }
}

function closeAllModulePickers(except = null) {
    document.querySelectorAll('[data-module-picker].is-open').forEach((picker) => {
        if (picker !== except) {
            closeModulePicker(picker);
        }
    });
}

export function bindAccessModeDropdownIn(root = document) {
    root.querySelectorAll('[data-access-mode-dropdown]').forEach((wrapper) => {
        if (wrapper.dataset.accessModeBound === '1') {
            return;
        }

        wrapper.dataset.accessModeBound = '1';

        const select = wrapper.querySelector('.member-access-mode');
        const trigger = wrapper.querySelector('.um-role-dropdown-trigger');
        const menu = wrapper.querySelector('.um-role-dropdown-menu');

        if (!select || !trigger || !menu) {
            return;
        }

        syncAccessModeDropdownFromSelect(select);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !wrapper.classList.contains('is-open');
            closeAllAccessModeDropdowns(willOpen ? wrapper : null);
            closeAllModulePickers();

            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;
        });

        menu.querySelectorAll('[data-access-mode-option]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                select.value = button.dataset.value || select.value;
                syncAccessModeDropdownFromSelect(select);
                closeAccessModeDropdown(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
}

export function bindModulePickerIn(root = document) {
    root.querySelectorAll('[data-module-picker]').forEach((picker) => {
        if (picker.dataset.modulePickerBound === '1') {
            return;
        }

        picker.dataset.modulePickerBound = '1';

        const trigger = picker.querySelector('.um-module-picker-trigger');
        const menu = picker.querySelector('.um-module-picker-menu');

        if (!trigger || !menu) {
            return;
        }

        syncModulePickerLabel(picker);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !picker.classList.contains('is-open');
            closeAllModulePickers(willOpen ? picker : null);
            closeAllAccessModeDropdowns();

            picker.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;
        });

        picker.querySelectorAll('[data-module-option]').forEach((input) => {
            input.addEventListener('change', () => {
                syncModulePickerLabel(picker);
            });
        });
    });
}

function syncModuleAccessRoleDropdownFromSelect(select) {
    const wrapper = select?.closest('[data-module-access-role-dropdown]');
    if (!wrapper) {
        return;
    }

    const label = wrapper.querySelector('.um-role-dropdown-label');
    const option = select.selectedOptions?.[0];
    if (label && option) {
        label.textContent = option.textContent.trim();
    }

    wrapper.querySelectorAll('[data-module-access-role-option]').forEach((button) => {
        const selected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
}

function closeModuleAccessRoleDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.um-role-dropdown-trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper.querySelector('.um-role-dropdown-menu');
    if (menu) {
        menu.hidden = true;
    }
}

function closeAllModuleAccessRoleDropdowns(except = null) {
    document.querySelectorAll('[data-module-access-role-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeModuleAccessRoleDropdown(wrapper);
        }
    });
}

export function bindModuleAccessRoleDropdownIn(root = document) {
    root.querySelectorAll('[data-module-access-role-dropdown]').forEach((wrapper) => {
        if (wrapper.closest('.um-module-access-source') || wrapper.dataset.moduleAccessRoleBound === '1') {
            return;
        }

        wrapper.dataset.moduleAccessRoleBound = '1';

        const select = wrapper.querySelector('[data-module-access-role-select]');
        const trigger = wrapper.querySelector('.um-role-dropdown-trigger');
        const menu = wrapper.querySelector('.um-role-dropdown-menu');
        const fixedRole = wrapper.closest('[data-member-action="modules"]')?.dataset.memberRole || '';

        if (!select || !trigger || !menu) {
            return;
        }

        syncModuleAccessRoleDropdownFromSelect(select);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !wrapper.classList.contains('is-open');
            closeAllModuleAccessRoleDropdowns(willOpen ? wrapper : null);
            closeAllRoleDropdowns();
            closeAllAccessModeDropdowns();

            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            menu.hidden = !willOpen;
        });

        menu.querySelectorAll('[data-module-access-role-option]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();

                if (fixedRole) {
                    select.value = fixedRole;
                    syncModuleAccessRoleDropdownFromSelect(select);
                    closeModuleAccessRoleDropdown(wrapper);

                    return;
                }

                select.value = button.dataset.value || select.value;
                syncModuleAccessRoleDropdownFromSelect(select);
                closeModuleAccessRoleDropdown(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
}

function moduleScopeMatchesRole(role) {
    const adminRoles = new Set(['admin', 'manager']);
    const portalRoles = new Set([
        'appointment_setter_team_lead',
        'closers_team_lead',
        'appointment_setter',
        'closer',
    ]);

    if (adminRoles.has(role)) {
        return (scopes) => scopes.length === 0 || scopes.some((scope) => adminRoles.has(scope));
    }

    if (portalRoles.has(role)) {
        return (scopes, itemRoles) =>
            scopes.includes(role) || (scopes.length === 0 && itemRoles.includes(role));
    }

    return () => false;
}

function filterModuleTickItems(form, role) {
    const list = form.querySelector('[data-module-tick-list]');
    if (!list) {
        return;
    }

    list.dataset.memberRole = role;
    const matches = moduleScopeMatchesRole(role);

    list.querySelectorAll('.um-module-tick-item').forEach((item) => {
        const scopes = (item.dataset.moduleScopes || '').split(',').filter(Boolean);
        const itemRoles = (item.dataset.moduleRoles || '').split(',').filter(Boolean);
        const visible = matches(scopes, itemRoles);
        item.classList.toggle('hidden', !visible);

        const input = item.querySelector('[data-module-option]');
        if (!input) {
            return;
        }

        if (!visible) {
            item.classList.remove('is-checked');
            input.checked = false;
            input.disabled = true;
        } else {
            input.disabled = false;
        }
    });

    list.querySelectorAll('.um-module-section').forEach((section) => {
        const hasVisibleItems = section.querySelector('.um-module-tick-item:not(.hidden)');
        section.classList.toggle('hidden', !hasVisibleItems);
    });

    syncModuleAccessModeInput(form);
}

function syncModuleTickItemState(item) {
    const input = item?.querySelector('[data-module-option]');
    if (!input) {
        return;
    }

    item.classList.toggle('is-checked', input.checked);
}

function syncModuleAccessModeInput(form) {
    const modeInput = form.querySelector('[data-module-access-mode]');
    if (!modeInput) {
        return;
    }

    const visibleInputs = [...form.querySelectorAll('.um-module-tick-item:not(.hidden) [data-module-option]:not(:disabled)')];
    const checkedCount = visibleInputs.filter((input) => input.checked).length;
    const allChecked = visibleInputs.length > 0 && checkedCount === visibleInputs.length;

    modeInput.value = allChecked ? 'full' : 'restricted';
}

function resetModuleAccessBindings(form) {
    delete form.dataset.moduleBound;
}

function syncModuleAccessRoleDisplay(form, role) {
    const fields = form.querySelector('.um-module-access-form-fields') || form;
    const display = fields.querySelector('[data-module-access-role-display]');
    if (!display) {
        return;
    }

    let labels = {};
    try {
        labels = JSON.parse(fields.dataset.roleLabels || '{}');
    } catch {
        labels = {};
    }

    display.textContent = labels[role] || role;
    display.dataset.role = role;
}

export function syncModuleAccessForRole(form, role) {
    if (!form) {
        return;
    }

    syncModuleAccessRoleDisplay(form, role);
    filterModuleTickItems(form, role);
}

export function bindModuleAccessFormFields(form) {
    if (!form) {
        return;
    }

    resetModuleAccessBindings(form);

    const host = form.closest('form') || form;
    const role =
        form.dataset.memberRole ||
        host.querySelector('[data-create-member-role]')?.value ||
        form.querySelector('[data-module-access-role-display]')?.dataset.role ||
        'admin';

    syncModuleAccessRoleDisplay(form, role);
    filterModuleTickItems(form, role);

    form.querySelectorAll('.um-module-tick-item').forEach((item) => {
        syncModuleTickItemState(item);
    });

    form.querySelectorAll('[data-module-option]').forEach((input) => {
        input.addEventListener('change', () => {
            syncModuleTickItemState(input.closest('.um-module-tick-item'));
            syncModuleAccessModeInput(form);
        });
    });

    form.dataset.moduleBound = '1';
}

function bindModuleAccessToggles() {
    document.querySelectorAll('.member-module-access').forEach((form) => {
        if (form.closest('.um-module-access-source')) {
            return;
        }

        bindModuleAccessFormFields(form);
    });
}

function closeModuleAccessModal() {
    const modal = document.getElementById('um-module-access-modal');
    if (!modal) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');

    const host = document.getElementById('um-module-access-form-host');
    if (host) {
        host.innerHTML = '';
    }
}

function bindModuleAccessFormSubmit(form) {
    if (!form || form.dataset.memberBound === '1') {
        return;
    }

    form.dataset.memberBound = '1';

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        syncModuleAccessModeInput(form);

        if (form.dataset.confirmed === '1') {
            form.dataset.confirmed = '0';
            submitMemberForm(form);

            return;
        }

        openConfirmModal(form);
    });

    form.querySelectorAll('[data-um-module-access-dismiss]').forEach((element) => {
        element.addEventListener('click', closeModuleAccessModal);
    });
}

function bindModuleAccessModal() {
    const modal = document.getElementById('um-module-access-modal');
    const host = document.getElementById('um-module-access-form-host');
    if (!modal || !host) {
        return;
    }

    const open = (button) => {
        const source = document.getElementById(`um-module-access-source-${button.dataset.memberId}`);
        if (!source) {
            return;
        }

        const sourceForm = source.querySelector('form');
        if (!sourceForm) {
            return;
        }

        host.innerHTML = '';
        const form = sourceForm.cloneNode(true);
        host.appendChild(form);

        const title = document.getElementById('um-module-access-title');
        const desc = document.getElementById('um-module-access-desc');
        const memberName = button.dataset.memberName || 'this member';

        if (title) {
            title.textContent = 'Module access';
        }

        if (desc) {
            const memberRole = button.dataset.memberRole || '';
            const agentRoles = new Set(['appointment_setter', 'closer']);
            const teamLeadRoles = new Set(['appointment_setter_team_lead', 'closers_team_lead']);

            if (agentRoles.has(memberRole)) {
                desc.textContent = `Choose which agent modules ${memberName} can access.`;
            } else if (teamLeadRoles.has(memberRole)) {
                desc.textContent = `Choose which portal modules ${memberName} can access as a team lead.`;
            } else {
                desc.textContent = `Choose which admin modules ${memberName} can access.`;
            }
        }

        bindModuleAccessFormFields(form);
        delete form.dataset.memberBound;
        bindModuleAccessFormSubmit(form);

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
    };

    document.querySelectorAll('[data-um-module-access-open]').forEach((button) => {
        if (button.dataset.moduleAccessBound === '1') {
            return;
        }

        button.dataset.moduleAccessBound = '1';
        button.addEventListener('click', () => open(button));
    });

    if (modal.dataset.bound !== '1') {
        modal.dataset.bound = '1';

        modal.querySelectorAll('[data-um-module-access-dismiss]').forEach((element) => {
            element.addEventListener('click', closeModuleAccessModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModuleAccessModal();
            }
        });
    }
}

function closeEditMemberModal() {
    const modal = document.getElementById('um-edit-member-modal');
    if (!modal) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('member-confirm-open');
}

function closeResetPasswordModal() {
    const modal = document.getElementById('um-reset-password-modal');
    if (!modal) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('member-confirm-open');
}

function closeAllMemberModals() {
    closeConfirmModal({ revertRole: false });
    closeEditMemberModal();
    closeResetPasswordModal();
    closeModuleAccessModal();

    const addModal = document.getElementById('um-add-member-modal');
    if (addModal) {
        addModal.hidden = true;
        addModal.setAttribute('aria-hidden', 'true');
    }

    const createWorkspaceModal = document.getElementById('um-create-workspace-modal');
    if (createWorkspaceModal) {
        createWorkspaceModal.hidden = true;
        createWorkspaceModal.setAttribute('aria-hidden', 'true');
    }

    document.body.classList.remove('member-confirm-open');
}

function prepareMemberFormData(form) {
    // Disabled assignment selects are excluded from FormData — re-enable briefly and
    // copy values so Edit/Add always send campaign + team lead when visible.
    const previouslyDisabled = [];
    form.querySelectorAll('select[disabled]').forEach((select) => {
        const field = select.closest('[data-edit-campaign-field], [data-edit-team-lead-field], [data-create-campaign-field], [data-create-team-lead-field]');
        if (field && !field.hidden) {
            previouslyDisabled.push(select);
            select.disabled = false;
        }
    });

    const formData = new FormData(form);

    previouslyDisabled.forEach((select) => {
        select.disabled = true;
    });

    const password = String(formData.get('password') || '');
    const confirmation = String(formData.get('password_confirmation') || '');

    // Optional password on Edit: omit blank values so autofill leftovers do not fail validation.
    if (form.dataset.memberAction === 'update' && password === '') {
        formData.delete('password');
        formData.delete('password_confirmation');
    }

    if (form.dataset.memberAction === 'update' && password !== '' && password !== confirmation) {
        throw new Error('Password confirmation does not match.');
    }

    if (form.dataset.memberAction === 'update' && password !== '' && password.length < 6) {
        throw new Error('Password must be at least 6 characters.');
    }

    return formData;
}

function parseLeadJson(raw) {
    try {
        const parsed = JSON.parse(raw || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function fillEditTeamLeadOptions(select, role, selectedId = '') {
    if (!select) {
        return;
    }

    const modal = document.getElementById('um-edit-member-modal');
    const setterLeads = parseLeadJson(modal?.dataset.setterLeads);
    const closerLeads = parseLeadJson(modal?.dataset.closerLeads);
    const leads = role === 'closer' || role === 'closers_team_lead' || role === 'closers_qa'
        ? closerLeads
        : setterLeads;

    // Prefer an explicit selection; otherwise default agents to the first matching team lead
    // so B2B Fronter → B2B Closer lands under the first closer TL instead of Unassigned.
    let effectiveSelectedId = selectedId;
    if (!effectiveSelectedId && (role === 'closer' || role === 'appointment_setter') && leads.length > 0) {
        effectiveSelectedId = String(leads[0].id);
    }

    select.innerHTML = '<option value="">Unassigned</option>';
    leads.forEach((lead) => {
        const option = document.createElement('option');
        option.value = String(lead.id);
        option.dataset.campaignId = lead.campaign_id ? String(lead.campaign_id) : '';
        option.textContent = lead.campaign_name
            ? `${lead.name} · ${lead.campaign_name}`
            : lead.name;
        if (String(lead.id) === String(effectiveSelectedId || '')) {
            option.selected = true;
        }
        select.appendChild(option);
    });
}

function refreshAssignmentPrettySelects(...selectors) {
    selectors.forEach((selector) => {
        const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
        if (el) {
            refreshPrettySelect(el);
        }
    });
}

function syncEditAssignmentFields(selectedRole, { teamLeadId = '', campaignId = '' } = {}) {
    const campaignField = document.querySelector('[data-edit-campaign-field]');
    const teamLeadField = document.querySelector('[data-edit-team-lead-field]');
    const campaignSelect = document.querySelector('[data-edit-member-campaign]');
    const teamLeadSelect = document.querySelector('[data-edit-member-team-lead]');
    const isTeamLead = selectedRole === 'appointment_setter_team_lead' || selectedRole === 'closers_team_lead';
    const isAgent = selectedRole === 'appointment_setter' || selectedRole === 'closer';
    const showCampaign = isTeamLead || isAgent;

    if (campaignField) {
        campaignField.hidden = !showCampaign;
    }
    if (teamLeadField) {
        teamLeadField.hidden = !isAgent;
    }

    if (campaignSelect) {
        if (showCampaign) {
            campaignSelect.disabled = false;
            campaignSelect.removeAttribute('disabled');
            campaignSelect.value = campaignId ? String(campaignId) : '';
        } else {
            campaignSelect.value = '';
            campaignSelect.disabled = true;
        }
    }

    if (teamLeadSelect) {
        if (isAgent) {
            teamLeadSelect.disabled = false;
            teamLeadSelect.removeAttribute('disabled');
            fillEditTeamLeadOptions(teamLeadSelect, selectedRole, teamLeadId);
        } else {
            teamLeadSelect.innerHTML = '<option value="">Unassigned</option>';
            teamLeadSelect.value = '';
            teamLeadSelect.disabled = true;
        }
    }

    refreshAssignmentPrettySelects(campaignSelect, teamLeadSelect, '[data-edit-member-role]');
}

function bindEditMemberModal() {
    const modal = document.getElementById('um-edit-member-modal');
    const form = document.getElementById('um-edit-member-form');
    if (!modal || !form) {
        return;
    }

    const open = (button) => {
        form.action = button.dataset.editUrl || '#';
        form.dataset.memberName = button.dataset.memberName || 'this member';

        const memberRole = button.dataset.memberRole || 'appointment_setter';
        const isSuperAdmin = memberRole === 'super_admin';

        const desc = document.getElementById('um-edit-member-desc');
        if (desc) {
            desc.textContent = isSuperAdmin
                ? `Update email and password for ${form.dataset.memberName}. The Super Admin role cannot be changed.`
                : `Update username, email, password, role, campaign, and team assignment for ${form.dataset.memberName}.`;
        }

        const usernameInput = document.getElementById('um-edit-member-username');
        const emailInput = document.getElementById('um-edit-member-email');
        const roleSelect = document.querySelector('[data-edit-member-role]');
        const roleField = roleSelect?.closest('.um-field');

        if (usernameInput) {
            usernameInput.value = button.dataset.memberName || '';
        }
        if (emailInput) {
            emailInput.value = button.dataset.memberEmail || '';
        }
        if (roleSelect) {
            roleSelect.value = isSuperAdmin ? 'admin' : memberRole;
            roleSelect.disabled = isSuperAdmin;
            if (isSuperAdmin) {
                roleSelect.removeAttribute('name');
            } else {
                roleSelect.setAttribute('name', 'role');
            }
        }
        if (roleField) {
            roleField.hidden = isSuperAdmin;
        }

        const passwordInput = form.querySelector('[name="password"]');
        const confirmationInput = form.querySelector('[name="password_confirmation"]');
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.removeAttribute('minlength');
            passwordInput.placeholder = button.dataset.memberPasswordHint
                ? `Leave blank to keep (${button.dataset.memberPasswordHint})`
                : 'Leave blank to keep current';
        }
        if (confirmationInput) {
            confirmationInput.value = '';
            confirmationInput.removeAttribute('minlength');
        }

        syncEditAssignmentFields(isSuperAdmin ? '' : memberRole, {
            teamLeadId: button.dataset.memberTeamLeadId || '',
            campaignId: button.dataset.memberCampaignId || '',
        });

        const campaignField = document.querySelector('[data-edit-campaign-field]');
        const teamLeadField = document.querySelector('[data-edit-team-lead-field]');
        if (isSuperAdmin) {
            if (campaignField) {
                campaignField.hidden = true;
            }
            if (teamLeadField) {
                teamLeadField.hidden = true;
            }
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        usernameInput?.focus();
    };

    document.querySelectorAll('[data-um-edit-member-open]').forEach((button) => {
        if (button.dataset.editBound === '1') {
            return;
        }

        button.dataset.editBound = '1';
        button.addEventListener('click', () => open(button));
    });

    const roleSelect = document.querySelector('[data-edit-member-role]');
    if (roleSelect && roleSelect.dataset.bound !== '1') {
        roleSelect.dataset.bound = '1';
        roleSelect.addEventListener('change', () => {
            syncEditAssignmentFields(roleSelect.value, {
                teamLeadId: document.querySelector('[data-edit-member-team-lead]')?.value || '',
                campaignId: document.querySelector('[data-edit-member-campaign]')?.value || '',
            });
        });
    }

    const teamLeadSelect = document.querySelector('[data-edit-member-team-lead]');
    if (teamLeadSelect && teamLeadSelect.dataset.campaignSyncBound !== '1') {
        teamLeadSelect.dataset.campaignSyncBound = '1';
        teamLeadSelect.addEventListener('change', () => {
            const campaignSelect = document.querySelector('[data-edit-member-campaign]');
            if (!campaignSelect || campaignSelect.disabled) {
                return;
            }
            const selected = teamLeadSelect.selectedOptions?.[0];
            const inherited = selected?.dataset?.campaignId || '';
            if (inherited) {
                campaignSelect.value = inherited;
                refreshPrettySelect(campaignSelect);
            }
        });
    }

    if (modal.dataset.bound !== '1') {
        modal.dataset.bound = '1';

        modal.querySelectorAll('[data-um-edit-member-dismiss]').forEach((element) => {
            element.addEventListener('click', closeEditMemberModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeEditMemberModal();
            }
        });
    }

    if (form.dataset.memberBound !== '1') {
        form.dataset.memberBound = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const passwordInput = form.querySelector('[name="password"]');
            const confirmationInput = form.querySelector('[name="password_confirmation"]');
            const password = passwordInput?.value || '';
            const confirmation = confirmationInput?.value || '';

            // Clear optional blank password fields so HTML5 minlength does not block Save.
            if (password === '') {
                if (passwordInput) {
                    passwordInput.value = '';
                    passwordInput.removeAttribute('minlength');
                }
                if (confirmationInput) {
                    confirmationInput.value = '';
                    confirmationInput.removeAttribute('minlength');
                }
            } else {
                passwordInput?.setAttribute('minlength', '6');
                confirmationInput?.setAttribute('minlength', '6');
            }

            if (password !== '' && password.length < 6) {
                showToast('Password must be at least 6 characters.', 'error');
                return;
            }

            if (password !== '' && password !== confirmation) {
                showToast('Password confirmation does not match.', 'error');
                return;
            }

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Save immediately — no second confirm popup — then close on success.
            submitMemberForm(form);
        });
    }
}

function bindResetPasswordModal() {
    const modal = document.getElementById('um-reset-password-modal');
    const form = document.getElementById('um-reset-password-form');
    if (!modal || !form) {
        return;
    }

    const open = (button) => {
        form.action = button.dataset.resetUrl || '#';
        form.dataset.memberName = button.dataset.memberName || 'this member';

        const desc = document.getElementById('um-reset-password-desc');
        if (desc) {
            const hint = button.dataset.memberPasswordHint || '';
            desc.textContent = hint
                ? `Set a new password for ${form.dataset.memberName}. Current password hint: ${hint}.`
                : `Set a new password for ${form.dataset.memberName}.`;
        }

        form.reset();
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.getElementById('um-reset-password-new')?.focus();
    };

    document.querySelectorAll('[data-um-reset-password-open]').forEach((button) => {
        if (button.dataset.resetBound === '1') {
            return;
        }

        button.dataset.resetBound = '1';
        button.addEventListener('click', () => open(button));
    });

    if (modal.dataset.bound !== '1') {
        modal.dataset.bound = '1';

        modal.querySelectorAll('[data-um-reset-password-dismiss]').forEach((element) => {
            element.addEventListener('click', closeResetPasswordModal);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.hidden) {
                closeResetPasswordModal();
            }
        });
    }

    if (form.dataset.memberBound !== '1') {
        form.dataset.memberBound = '1';

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            submitMemberForm(form);
        });
    }
}

function bindMemberForms() {
    document.querySelectorAll('form[data-member-action]').forEach((form) => {
        if (
            form.dataset.memberBound === '1' ||
            form.id === 'um-edit-member-form' ||
            form.id === 'um-reset-password-form' ||
            form.closest('.um-module-access-source')
        ) {
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
        closeConfirmModal({ revertRole: false });
        submitMemberForm(form);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeConfirmModal();
        }
    });
}

function roleOptionsHtml(roleLabels, selectedRole) {
    return Object.entries(roleLabels)
        .map(([value, label]) => {
            const selected = value === selectedRole ? 'selected' : '';

            return `<option value="${escapeHtml(value)}" ${selected}>${escapeHtml(label)}</option>`;
        })
        .join('');
}

export function renderAdminMemberRow(member, options) {
    const status = member.status || 'active';
    const role = member.role || 'sdr';
    const roleLabel = member.role_label || options.roleLabels[role] || role;
    const base = options.membersBase;
    const csrf = options.csrf;
    const canManage = member.can_manage;
    const roleOptions = roleOptionsHtml(options.roleLabels, role);

    const actions = canManage
        ? `<div class="member-row-actions flex flex-col gap-3 w-full sm:w-auto sm:min-w-[280px]">
                <form method="POST" action="${escapeHtml(base)}/${member.id}/role" data-member-action="role" data-member-name="${escapeHtml(member.name)}" class="flex items-center gap-2">
                    <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                    <input type="hidden" name="_method" value="PATCH">
                    <label class="text-xs text-slate-500 shrink-0">Role</label>
                    <select name="role" class="member-role-select flex-1 px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs">${roleOptions}</select>
                    <button type="submit" class="member-action-btn member-action-btn-role text-xs">Save</button>
                </form>
                <details class="member-reset-password text-xs">
                    <summary class="cursor-pointer text-indigo-600 font-medium">Reset password</summary>
                    <form method="POST" action="${escapeHtml(base)}/${member.id}/reset-password" data-member-action="reset-password" data-member-name="${escapeHtml(member.name)}" class="mt-2 space-y-2">
                        <input type="hidden" name="_token" value="${escapeHtml(csrf)}">
                        <input type="password" name="password" required minlength="6" placeholder="New password" class="w-full px-2 py-1.5 border border-slate-200 rounded-lg">
                        <input type="password" name="password_confirmation" required minlength="6" placeholder="Confirm password" class="w-full px-2 py-1.5 border border-slate-200 rounded-lg">
                        <button type="submit" class="member-action-btn member-action-btn-role w-full">Update password</button>
                    </form>
                </details>
                <div class="flex flex-wrap items-center gap-2">
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
                </div>
           </div>`
        : '';

    return `
        <div class="member-row py-4 flex flex-col sm:flex-row sm:items-start justify-between gap-4 ${status === 'suspended' ? 'member-row-suspended' : ''}" data-member-id="${member.id}" data-member-name="${escapeHtml(member.name)}">
            <div class="member-row-identity min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-bold text-slate-800 text-sm">${escapeHtml(member.name)}</span>
                    ${member.is_owner ? '<span class="member-owner-badge">Owner</span>' : ''}
                    <span class="member-status-badge member-status-${status}" data-member-status>${status === 'suspended' ? 'Suspended' : status === 'invited' ? 'Invited' : 'Active'}</span>
                </div>
                <div class="text-xs text-slate-400 mt-1 truncate">${escapeHtml(member.email)}</div>
                <div class="text-xs text-slate-500 mt-0.5" data-member-role>${escapeHtml(roleLabel)}</div>
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

    // Full re-render is complex; reload to keep UI in sync with server-rendered cards.
    if (members.length !== container.querySelectorAll('.member-row').length) {
        window.location.reload();
        return;
    }

    updateMemberRows(container, members);
}

function syncRoleDropdownFromSelect(select) {
    const wrapper = select?.closest('[data-role-dropdown]');
    if (!wrapper) {
        return;
    }

    const label = wrapper.querySelector('.um-role-dropdown-label');
    const option = select.selectedOptions?.[0];
    if (label && option) {
        label.textContent = option.textContent.trim();
        label.title = label.textContent;
    }

    const menu = wrapper._roleMenuEl || wrapper.querySelector('.um-role-dropdown-menu');
    menu?.querySelectorAll('[data-role-option]').forEach((button) => {
        const selected = button.dataset.value === select.value;
        button.classList.toggle('is-selected', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
    });
}

function clearRoleDropdownMenuPosition(menu) {
    if (!menu) {
        return;
    }

    menu.style.position = '';
    menu.style.top = '';
    menu.style.left = '';
    menu.style.right = '';
    menu.style.width = '';
    menu.style.minWidth = '';
    menu.style.maxWidth = '';
    menu.style.zIndex = '';
    menu.style.transform = '';
}

function positionRoleDropdownMenu(wrapper) {
    const trigger = wrapper?.querySelector('.um-role-dropdown-trigger');
    const menu = wrapper?._roleMenuEl || wrapper?.querySelector('.um-role-dropdown-menu');
    if (!trigger || !menu || menu.hidden) {
        return;
    }

    wrapper._roleMenuEl = menu;

    if (menu.parentElement !== document.body) {
        document.body.appendChild(menu);
    }

    const gap = 6;
    const edge = 8;
    const triggerRect = trigger.getBoundingClientRect();
    const preferredWidth = Math.min(Math.max(triggerRect.width, 240), window.innerWidth - edge * 2);

    menu.style.position = 'fixed';
    menu.style.zIndex = '12500';
    menu.style.width = `${preferredWidth}px`;
    menu.style.minWidth = `${Math.min(preferredWidth, 200)}px`;
    menu.style.maxWidth = `calc(100vw - ${edge * 2}px)`;
    menu.style.transform = 'none';
    menu.style.right = 'auto';
    menu.style.overflowX = 'hidden';

    let left = triggerRect.left;
    if (left + preferredWidth > window.innerWidth - edge) {
        left = Math.max(edge, window.innerWidth - edge - preferredWidth);
    }
    if (left < edge) {
        left = edge;
    }

    let top = triggerRect.bottom + gap;
    menu.style.left = `${Math.round(left)}px`;
    menu.style.top = `${Math.round(top)}px`;

    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        if (menuRect.bottom > window.innerHeight - edge) {
            const upTop = triggerRect.top - menuRect.height - gap;
            if (upTop >= edge) {
                menu.style.top = `${Math.round(upTop)}px`;
            }
        }
        menu.scrollLeft = 0;
    });
}

function closeRoleDropdown(wrapper) {
    if (!wrapper) {
        return;
    }

    wrapper.classList.remove('is-open');
    wrapper.querySelector('.um-role-dropdown-trigger')?.setAttribute('aria-expanded', 'false');
    const menu = wrapper._roleMenuEl || wrapper.querySelector('.um-role-dropdown-menu');
    if (menu) {
        menu.hidden = true;
        clearRoleDropdownMenuPosition(menu);
        if (menu.parentElement === document.body) {
            wrapper.appendChild(menu);
        }
    }
}

function closeAllRoleDropdowns(except = null) {
    document.querySelectorAll('[data-role-dropdown].is-open').forEach((wrapper) => {
        if (wrapper !== except) {
            closeRoleDropdown(wrapper);
        }
    });
}

function applyRoleOptionSelection(select, nextValue) {
    if (!select || !nextValue) {
        return false;
    }

    const previousValue = select.value;
    select.value = nextValue;
    Array.from(select.options).forEach((option) => {
        option.selected = option.value === nextValue;
    });
    syncRoleDropdownFromSelect(select);
    select.dispatchEvent(new Event('change', { bubbles: true }));

    return previousValue !== nextValue;
}

function bindRoleDropdowns() {
    document.querySelectorAll('[data-role-dropdown]').forEach((wrapper) => {
        if (wrapper.dataset.roleDropdownBound === '1') {
            return;
        }

        wrapper.dataset.roleDropdownBound = '1';

        const select = wrapper.querySelector('[data-member-role-select]');
        const trigger = wrapper.querySelector('.um-role-dropdown-trigger');
        const menu = wrapper.querySelector('.um-role-dropdown-menu');

        if (!select || !trigger || !menu) {
            return;
        }

        wrapper._roleMenuEl = menu;

        if (!select.dataset.initialRole) {
            select.dataset.initialRole = select.value;
        }

        syncRoleDropdownFromSelect(select);

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const willOpen = !wrapper.classList.contains('is-open');
            closeAllRoleDropdowns(willOpen ? wrapper : null);

            wrapper.classList.toggle('is-open', willOpen);
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            const activeMenu = wrapper._roleMenuEl || menu;
            activeMenu.hidden = !willOpen;

            if (willOpen) {
                positionRoleDropdownMenu(wrapper);
            } else {
                closeRoleDropdown(wrapper);
            }
        });

        menu.querySelectorAll('[data-role-option]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                const nextValue = button.dataset.value || '';
                const previousValue = select.value;
                const changed = applyRoleOptionSelection(select, nextValue);
                closeRoleDropdown(wrapper);

                if (!changed) {
                    return;
                }

                const form = select.closest('form[data-member-action="role"]');
                if (!form) {
                    return;
                }

                form.dataset.originalRole = previousValue;
                form.dataset.pendingRole = nextValue;
                form.dataset.pendingRoleLabel = button.textContent.trim() || nextValue;
                openConfirmModal(form);
            });
        });
    });

    if (document.body.dataset.roleDropdownGlobalBound === '1') {
        return;
    }

    document.body.dataset.roleDropdownGlobalBound = '1';

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-role-dropdown], .um-role-dropdown-menu')) {
            return;
        }

        closeAllRoleDropdowns();
    });

    window.addEventListener('scroll', () => {
        document.querySelectorAll('[data-role-dropdown].is-open').forEach((wrapper) => {
            positionRoleDropdownMenu(wrapper);
        });
    }, true);

    window.addEventListener('resize', () => {
        document.querySelectorAll('[data-role-dropdown].is-open').forEach((wrapper) => {
            positionRoleDropdownMenu(wrapper);
        });
    });

    if (document.body.dataset.accessModeDropdownGlobalBound !== '1') {
        document.body.dataset.accessModeDropdownGlobalBound = '1';

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-access-mode-dropdown]')) {
                return;
            }

            closeAllAccessModeDropdowns();
        });
    }

    if (document.body.dataset.modulePickerGlobalBound !== '1') {
        document.body.dataset.modulePickerGlobalBound = '1';

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-module-picker]')) {
                return;
            }

            closeAllModulePickers();
        });
    }

    if (document.body.dataset.moduleAccessRoleDropdownGlobalBound !== '1') {
        document.body.dataset.moduleAccessRoleDropdownGlobalBound = '1';

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-module-access-role-dropdown]')) {
                return;
            }

            closeAllModuleAccessRoleDropdowns();
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAllRoleDropdowns();
            closeAllAccessModeDropdowns();
            closeAllModulePickers();
            closeAllModuleAccessRoleDropdowns();
        }
    });
}

function bindMemberSearch() {
    const input = document.getElementById('um-member-search');
    const list = document.getElementById('workspace-sync-team');
    if (!input || !list) {
        return;
    }

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        list.querySelectorAll('.member-row').forEach((row) => {
            const haystack = row.dataset.memberSearch || row.textContent?.toLowerCase() || '';
            row.classList.toggle('um-search-hidden', query !== '' && !haystack.includes(query));
        });
    });
}

export function initMemberManagement() {
    if (!document.getElementById('workspace-member-management')) {
        return;
    }

    bindConfirmModal();
    bindEditMemberModal();
    bindResetPasswordModal();
    bindModuleAccessModal();
    bindMemberForms();
    bindModuleAccessToggles();
    bindMemberSearch();
    bindRoleDropdowns();
    bindModuleAccessRoleDropdownIn();
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
            badge.className = `member-status-badge member-status-${status} um-badge um-badge-status-${status}`;
            badge.textContent = status === 'suspended' ? 'Suspended' : status === 'invited' ? 'Invited' : 'Active';
        }

        const nameEl = row.querySelector('.um-member-name');
        if (nameEl && member.name) {
            nameEl.textContent = member.name;
        }

        const emailEl = row.querySelector('.um-member-email');
        if (emailEl && member.email) {
            emailEl.textContent = member.email;
        }

        const passwordEl = row.querySelector('[data-member-password-hint]');
        if (passwordEl && Object.prototype.hasOwnProperty.call(member, 'password_hint')) {
            passwordEl.textContent = member.password_hint || '—';
        }

        if (member.name) {
            row.dataset.memberName = member.name;
        }

        const roleEl = row.querySelector('[data-member-role]');
        if (roleEl) {
            roleEl.textContent = member.role_label || member.role || roleEl.textContent;
        }

        const moduleSummary = row.querySelector('[data-member-module-summary]');
        if (moduleSummary && member.module_summary) {
            moduleSummary.textContent = member.module_summary;
        }

        const roleSelect = row.querySelector('[data-member-role-select]');
        if (roleSelect && member.role) {
            roleSelect.value = member.role;
            syncRoleDropdownFromSelect(roleSelect);
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
        row.classList.toggle('um-member-row-suspended', status === 'suspended');
        row.classList.remove('member-row-busy');
    });
}
