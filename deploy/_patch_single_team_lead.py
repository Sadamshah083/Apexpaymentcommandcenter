#!/usr/bin/env python3
"""Simplify assign modal JS to a single Team lead dropdown."""

from pathlib import Path

PATH = Path(__file__).resolve().parents[1] / "resources/views/workflows/partials/import-modals.blade.php"

OLD_START = "    const assignTeamLeadId = document.getElementById('import-assign-team-lead-id');"
OLD_END = "    assignTeamCloser?.addEventListener('change', () => onTeamPickChange(assignTeamCloser));\n    assignMembersAll?.addEventListener('click', () => setMemberChecks(true));"

NEW = r'''    const assignTeamLead = document.getElementById('import-assign-team-lead');
    const assignAlert = document.getElementById('import-assign-alert');
    const assignSubmit = document.getElementById('import-assign-submit');
    const activeSetterCount = Number(@json($activeSetterCount));
    const assignBase = @json(url('/admin/workflows'));
    const closerTeamLeadRole = @json($closerTeamLeadRole);
    const setterTeamMemberMap = @json($setterTeamMemberMap);
    const allActiveSetters = @json($activeSettersPayload);
    const assignMembers = document.getElementById('import-assign-members');
    const assignMembersToolbar = document.getElementById('import-assign-members-toolbar');
    const assignMembersAll = document.getElementById('import-assign-members-all');
    const assignMembersNone = document.getElementById('import-assign-members-none');
    const assignMembersHint = document.getElementById('import-assign-members-hint');
    let activeWorkflowId = null;

    function ensurePrettySelects(root = assignModal) {
        if (root && typeof window.initPrettySelects === 'function') {
            window.initPrettySelects(root);
        }
    }

    function setMemberChecks(checked) {
        assignMembers?.querySelectorAll('input[name="member_ids[]"]').forEach((input) => {
            input.checked = checked;
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function membersForLead(leadId) {
        const mapped = setterTeamMemberMap[leadId] || setterTeamMemberMap[String(leadId)] || [];
        if (Array.isArray(mapped) && mapped.length > 0) {
            return mapped;
        }
        return allActiveSetters.filter((member) => !member.team_lead_user_id || Number(member.team_lead_user_id) === Number(leadId));
    }

    function selectedTeamLeadRole() {
        return assignTeamLead?.selectedOptions?.[0]?.dataset?.teamLeadRole || '';
    }

    function renderTeamMembers() {
        if (!assignMembers || !assignTeamLead) {
            return;
        }
        const leadId = assignTeamLead.value;
        if (!leadId) {
            assignMembers.innerHTML = '<p class="assign-leads-members-empty" data-members-empty>Select a team lead to see their members.</p>';
            if (assignMembersToolbar) assignMembersToolbar.hidden = true;
            if (assignMembersHint) {
                assignMembersHint.textContent = 'Leave all selected to split leads across every member on that team.';
            }
            return;
        }

        const members = membersForLead(leadId);
        if (assignMembersToolbar) {
            assignMembersToolbar.hidden = members.length === 0;
        }

        const isCloserTeam = selectedTeamLeadRole() === closerTeamLeadRole;
        const memberLabel = isCloserTeam ? 'closers' : 'setters';

        if (members.length === 0) {
            assignMembers.innerHTML = `<p class="assign-leads-members-empty assign-leads-members-empty--warn">No team members linked to this lead yet. Link ${memberLabel} under User Management, or assignment will use all active ${memberLabel} in the workspace.</p>`;
            if (assignMembersHint) {
                assignMembersHint.textContent = `Link ${memberLabel} to this team lead so they appear here.`;
            }
            return;
        }

        assignMembers.innerHTML = members.map((member) => `
            <label class="assign-leads-member-row">
                <input type="checkbox" name="member_ids[]" value="${Number(member.id)}" checked class="assign-leads-member-check">
                <span class="assign-leads-member-name">${escapeHtml(member.name)}</span>
            </label>
        `).join('');
        if (assignMembersHint) {
            assignMembersHint.textContent = 'Uncheck a member to exclude them from this assignment.';
        }
    }

    function showAssignAlert(message, type = 'error') {
        if (!assignAlert) {
            return;
        }

        assignAlert.textContent = message;
        assignAlert.classList.remove('hidden', 'border-rose-200', 'bg-rose-50', 'text-rose-800', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800');

        if (type === 'success') {
            assignAlert.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
        } else {
            assignAlert.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800');
        }
    }

    function hideAssignAlert() {
        assignAlert?.classList.add('hidden');
    }

    function updateAssignButtonState(remaining) {
        if (!assignSubmit) {
            return;
        }

        const canSubmit = remaining > 0 && activeSetterCount > 0 && Boolean(assignTeamLead?.value);
        assignSubmit.disabled = !canSubmit;
    }

    function openDeleteModal(button) {
        if (!deleteModal || !deleteForm) return;
        const id = button.dataset.workflowId;
        const name = button.dataset.workflowName || 'this import';
        const total = button.dataset.workflowTotal || '0';
        deleteForm.action = `${assignBase}/${id}`;
        deleteMessage.textContent = `Delete "${name}" and all ${total} lead records from the database? This cannot be undone.`;
        deleteModal.hidden = false;
        deleteModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
    }

    function closeDeleteModal() {
        if (!deleteModal) return;
        deleteModal.hidden = true;
        deleteModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('member-confirm-open');
        const submit = document.getElementById('import-delete-submit');
        if (submit) {
            submit.disabled = false;
            submit.textContent = 'Delete permanently';
        }
    }

    deleteForm?.addEventListener('submit', () => {
        document.dispatchEvent(new CustomEvent('workspace:teardown-request'));
        const submit = document.getElementById('import-delete-submit');
        if (submit) {
            submit.disabled = true;
            submit.textContent = 'Deleting…';
        }
        deleteModal?.querySelectorAll('[data-import-delete-dismiss]').forEach((el) => {
            el.setAttribute('disabled', 'disabled');
        });
    });

    function pickPreferredTeamLead() {
        if (!assignTeamLead) return;
        const options = [...assignTeamLead.options].filter((option) => option.value);
        const withCampaign = options.find((option) => Number(option.dataset.campaignId || 0) > 0);
        const preferred = withCampaign || options[0];
        assignTeamLead.value = preferred ? preferred.value : '';
        renderTeamMembers();
    }

    function openAssignModal(button) {
        if (!assignModal || !assignForm) return;
        const id = button.dataset.workflowId;
        const name = button.dataset.workflowName || 'Import';
        const total = Number(button.dataset.workflowTotal || 0);
        const assigned = Number(button.dataset.workflowAssigned || 0);
        const remaining = Number(button.dataset.workflowRemaining || 0);
        activeWorkflowId = id;
        hideAssignAlert();
        assignForm.action = `${assignBase}/${id}/assign-leads`;
        assignTitle.textContent = `Assign: ${name}`;
        assignDesc.textContent = remaining > 0
            ? `${remaining.toLocaleString()} enriched lead(s) ready to assign. Choose a team lead, then confirm which team members should receive leads.`
            : 'No enriched unassigned leads remain in this import.';
        assignStats.innerHTML = `
            <div class="assign-leads-stat"><span class="assign-leads-stat__label">Total</span><strong class="assign-leads-stat__value">${total.toLocaleString()}</strong></div>
            <div class="assign-leads-stat"><span class="assign-leads-stat__label">Assigned</span><strong class="assign-leads-stat__value assign-leads-stat__value--success">${assigned.toLocaleString()}</strong></div>
            <div class="assign-leads-stat"><span class="assign-leads-stat__label">Ready</span><strong class="assign-leads-stat__value assign-leads-stat__value--ready" id="import-assign-remaining-value">${remaining.toLocaleString()}</strong></div>`;
        assignCount.max = Math.max(1, remaining);
        assignCount.value = remaining > 0 ? remaining : 1;
        pickPreferredTeamLead();
        updateAssignButtonState(remaining);
        assignModal.hidden = false;
        assignModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        ensurePrettySelects(assignModal);
        assignTeamLead?.focus?.();
    }

    function closeAssignModal() {
        if (!assignModal) return;
        assignModal.hidden = true;
        assignModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('member-confirm-open');
        activeWorkflowId = null;
        hideAssignAlert();
    }

    function patchAssignRowStats(workflowId, assignedDelta, remaining) {
        const row = document.querySelector(`tr[data-workflow-id="${workflowId}"]`);
        if (!row || !row.cells[6] || !row.cells[7]) {
            return;
        }

        const assignedCell = row.cells[6];
        const remainingCell = row.cells[7];
        const currentAssigned = Number((assignedCell.textContent || '0').replace(/,/g, '')) || 0;
        assignedCell.textContent = (currentAssigned + assignedDelta).toLocaleString();
        remainingCell.textContent = Math.max(0, remaining).toLocaleString();

        const assignBtn = row.querySelector('[data-import-assign-open]');
        if (assignBtn) {
            assignBtn.dataset.workflowAssigned = String(currentAssigned + assignedDelta);
            assignBtn.dataset.workflowRemaining = String(Math.max(0, remaining));
        }

        if (remaining <= 0) {
            const assignCell = row.cells[8];
            if (assignCell) {
                assignCell.innerHTML = '<span class="import-assign-empty">&mdash;</span>';
            }
        }
    }

    assignForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideAssignAlert();

        if (!assignForm.action || assignForm.action.endsWith('#')) {
            showAssignAlert('Choose an import before assigning leads.');
            return;
        }

        if (!assignTeamLead?.value) {
            showAssignAlert('Select a team lead before assigning leads.');
            return;
        }

        if (activeSetterCount === 0) {
            showAssignAlert('Add at least one active setter or closer before assigning leads.');
            return;
        }

        const memberBoxes = [...(assignMembers?.querySelectorAll('input[name="member_ids[]"]') || [])];
        const checkedMembers = memberBoxes.filter((input) => input.checked);
        if (memberBoxes.length > 0 && checkedMembers.length === 0) {
            showAssignAlert('Select at least one team member to receive leads.');
            return;
        }

        const submitButton = assignSubmit;
        const originalText = submitButton?.textContent || 'Assign leads';
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Assigning…';
        }

        try {
            const response = await fetch(assignForm.action, {
                method: 'POST',
                body: new FormData(assignForm),
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
                    || 'Could not assign leads.';
                showAssignAlert(message, 'error');
                window.showToast?.(message, 'error');
                return;
            }

            const assigned = Number(payload.assigned ?? 0);
            const remaining = Number(payload.remaining ?? 0);
            const message = payload.message || `Assigned ${assigned} lead(s).`;
            showAssignAlert(message, 'success');
            window.showToast?.(message, 'success');

            if (activeWorkflowId) {
                patchAssignRowStats(activeWorkflowId, assigned, remaining);
            }

            const remainingEl = document.getElementById('import-assign-remaining-value');
            if (remainingEl) {
                remainingEl.textContent = Math.max(0, remaining).toLocaleString();
            }

            assignCount.max = Math.max(1, remaining);
            assignCount.value = remaining > 0 ? Math.min(remaining, Number(assignCount.value || 1)) : 1;
            updateAssignButtonState(remaining);

            if (remaining <= 0) {
                window.setTimeout(() => closeAssignModal(), 900);
            }
        } catch {
            const message = 'Network error while assigning leads.';
            showAssignAlert(message, 'error');
            window.showToast?.(message, 'error');
        } finally {
            if (submitButton) {
                submitButton.textContent = originalText;
                updateAssignButtonState(Number(document.getElementById('import-assign-remaining-value')?.textContent?.replace(/,/g, '') || 0));
            }
        }
    });

    assignTeamLead?.addEventListener('change', () => {
        renderTeamMembers();
        updateAssignButtonState(Number(assignCount?.max || 0));
    });
    assignMembersAll?.addEventListener('click', () => setMemberChecks(true));'''


def main() -> int:
    text = PATH.read_text(encoding="utf-8")
    start = text.find(OLD_START)
    end = text.find(OLD_END)
    if start < 0 or end < 0:
        print("markers not found", start, end)
        return 1
    end = end + len(OLD_END)
    PATH.write_text(text[:start] + NEW + text[end:], encoding="utf-8")
    print("patched", PATH)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
