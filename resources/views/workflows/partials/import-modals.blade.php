@php
    use App\Support\SalesOps;
    use App\Support\WorkflowAssignmentRoles;

    $teamLeads = $setterTeamLeads ?? $teamLeads ?? collect();
    $activeSetters = collect($activeSetters ?? []);
    $setterTeamMemberMap = $setterTeamMemberMap ?? [];
    $setterTeamLeadRole = WorkflowAssignmentRoles::setterTeamLeadRole();
    $activeSetterCount = (int) ($activeSetterCount ?? $activeSetters->count());
    $activeSettersPayload = $activeSetters->map(static function ($user) {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'team_lead_user_id' => (int) ($user->pivot->team_lead_user_id ?? 0),
        ];
    })->values()->all();
@endphp

<div
    id="import-delete-modal"
    class="member-confirm-modal"
    hidden
    aria-hidden="true"
    role="dialog"
    aria-labelledby="import-delete-title"
    aria-modal="true"
>
    <div class="member-confirm-backdrop" data-import-delete-dismiss></div>
    <div class="member-confirm-panel" role="document">
        <div class="member-confirm-icon member-confirm-icon-warning" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h2 id="import-delete-title" class="member-confirm-title">Delete import?</h2>
        <p id="import-delete-message" class="member-confirm-message text-left">This will permanently remove the import and all lead records from the database.</p>
        <form id="import-delete-form" method="POST" action="#" data-turbo="true">
            @csrf
            @method('DELETE')
            <div class="member-confirm-actions">
                <button type="button" class="member-confirm-cancel" data-import-delete-dismiss>Cancel</button>
                <button type="submit" id="import-delete-submit" class="member-confirm-submit bg-rose-600 hover:bg-rose-700">Delete permanently</button>
            </div>
        </form>
    </div>
</div>

<div
    id="import-assign-modal"
    class="member-confirm-modal"
    hidden
    aria-hidden="true"
    role="dialog"
    aria-labelledby="import-assign-title"
    aria-modal="true"
>
    <div class="member-confirm-backdrop" data-import-assign-dismiss></div>
    <div class="member-confirm-panel assign-leads-panel" role="document">
        <h2 id="import-assign-title" class="member-confirm-title text-left">Assign leads</h2>
        <p id="import-assign-desc" class="member-confirm-message text-left mb-4">Assign enriched leads to a team lead. Leads are distributed across that team’s active setters.</p>

        <div id="import-assign-stats" class="grid grid-cols-3 gap-2 mb-4 text-center text-xs"></div>

        <div id="import-assign-alert" class="hidden mb-4 rounded-lg border px-3 py-2 text-sm" role="alert"></div>

        @if ($activeSetterCount === 0)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                No active appointment setters in this workspace. Add setter accounts under User Management before assigning leads.
            </div>
        @endif

        <form id="import-assign-form" method="POST" action="#" class="space-y-4 text-left">
            @csrf
            <div class="app-field">
                <label for="import-assign-team-lead" class="app-label">Team lead</label>
                <select name="team_lead_id" id="import-assign-team-lead" class="app-input w-full" required @disabled($teamLeads->isEmpty())>
                    <option value="">Select team lead…</option>
                    @foreach($teamLeads as $teamLead)
                        <option
                            value="{{ $teamLead->id }}"
                            data-team-lead-role="{{ $teamLead->pivot->role }}"
                            @selected($teamLead->pivot->role === $setterTeamLeadRole)
                        >{{ $teamLead->name }} ({{ SalesOps::roleLabel($teamLead->pivot->role) }})</option>
                    @endforeach
                </select>
                @if($teamLeads->isEmpty())
                    <p class="text-xs text-amber-700 mt-1">No team leads in this workspace. Add an Appointment Setter Team Lead or Closers Team Lead.</p>
                @else
                    <p class="text-xs text-zinc-500 mt-1">Only Appointment Setter Team Lead can receive enriched import leads.</p>
                @endif
            </div>
            <div class="app-field" id="import-assign-members-field">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <label class="app-label mb-0">Team members</label>
                    <button type="button" class="text-xs font-semibold text-indigo-700 hover:underline" id="import-assign-members-all" hidden>Select all</button>
                </div>
                <div id="import-assign-members" class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 space-y-1 max-h-40 overflow-auto text-sm">
                    <p class="text-zinc-500 text-xs" data-members-empty>Select a team lead to see their members.</p>
                </div>
                <p class="text-xs text-zinc-500 mt-1" id="import-assign-members-hint">
                    Leave all selected to split leads across every member on that team.
                </p>
            </div>
            <div class="app-field">
                <label for="import-assign-count" class="app-label">Number of leads</label>
                <input type="number" name="lead_count" id="import-assign-count" class="app-input w-full" min="1" value="1" required>
            </div>
            <div class="member-confirm-actions !justify-end">
                <button type="button" class="member-confirm-cancel" data-import-assign-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit" id="import-assign-submit" @disabled($teamLeads->isEmpty() || $activeSetterCount === 0)>Assign leads</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(() => {
    const deleteModal = document.getElementById('import-delete-modal');
    const deleteForm = document.getElementById('import-delete-form');
    const deleteMessage = document.getElementById('import-delete-message');
    const assignModal = document.getElementById('import-assign-modal');
    const assignForm = document.getElementById('import-assign-form');
    const assignTitle = document.getElementById('import-assign-title');
    const assignDesc = document.getElementById('import-assign-desc');
    const assignStats = document.getElementById('import-assign-stats');
    const assignCount = document.getElementById('import-assign-count');
    const assignTeamLead = document.getElementById('import-assign-team-lead');
    const assignAlert = document.getElementById('import-assign-alert');
    const assignSubmit = document.getElementById('import-assign-submit');
    const activeSetterCount = Number(@json($activeSetterCount));
    const assignBase = @json(url('/admin/workflows'));
    const setterTeamLeadRole = @json($setterTeamLeadRole);
    const setterTeamMemberMap = @json($setterTeamMemberMap);
    const allActiveSetters = @json($activeSettersPayload);
    const assignMembers = document.getElementById('import-assign-members');
    const assignMembersAll = document.getElementById('import-assign-members-all');
    const assignMembersHint = document.getElementById('import-assign-members-hint');
    let activeWorkflowId = null;

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

    function renderTeamMembers() {
        if (!assignMembers || !assignTeamLead) {
            return;
        }
        const leadId = assignTeamLead.value;
        if (!leadId) {
            assignMembers.innerHTML = '<p class="text-zinc-500 text-xs" data-members-empty>Select a team lead to see their members.</p>';
            if (assignMembersAll) assignMembersAll.hidden = true;
            if (assignMembersHint) {
                assignMembersHint.textContent = 'Leave all selected to split leads across every member on that team.';
            }
            return;
        }

        const members = membersForLead(leadId);
        if (assignMembersAll) {
            assignMembersAll.hidden = members.length === 0;
        }

        if (members.length === 0) {
            assignMembers.innerHTML = '<p class="text-amber-700 text-xs">No team members linked to this lead yet. Link setters under User Management, or assignment will use all active setters in the workspace.</p>';
            if (assignMembersHint) {
                assignMembersHint.textContent = 'Link appointment setters to this team lead so they appear here.';
            }
            return;
        }

        assignMembers.innerHTML = members.map((member) => `
            <label class="flex items-center gap-2 py-0.5 cursor-pointer">
                <input type="checkbox" name="member_ids[]" value="${Number(member.id)}" checked class="rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500">
                <span>${escapeHtml(member.name)}</span>
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
        // Stop live sync/monitoring immediately so Network does not keep streaming a deleted import.
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

    function syncAssignTeamLeadDefault() {
        if (!assignTeamLead) return;
        const setterOption = [...assignTeamLead.options].find(
            (option) => option.dataset.teamLeadRole === setterTeamLeadRole,
        );
        if (setterOption) {
            assignTeamLead.value = setterOption.value;
        }
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
            ? `${remaining.toLocaleString()} unassigned lead(s) ready to assign. Choose a team lead, then confirm which team members should receive leads.`
            : 'No unassigned leads remain in this import.';
        assignStats.innerHTML = `
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Total</span><strong>${total.toLocaleString()}</strong></div>
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Assigned</span><strong class="text-emerald-700">${assigned.toLocaleString()}</strong></div>
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Unassigned</span><strong class="text-amber-700" id="import-assign-remaining-value">${remaining.toLocaleString()}</strong></div>`;
        assignCount.max = Math.max(1, remaining);
        assignCount.value = remaining > 0 ? remaining : 1;
        syncAssignTeamLeadDefault();
        updateAssignButtonState(remaining);
        assignModal.hidden = false;
        assignModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        assignCount.focus();
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

        if (activeSetterCount === 0) {
            showAssignAlert('Add at least one active appointment setter before assigning leads.');
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
    assignMembersAll?.addEventListener('click', () => {
        assignMembers?.querySelectorAll('input[name="member_ids[]"]').forEach((input) => {
            input.checked = true;
        });
    });

    document.addEventListener('click', (event) => {
        const deleteBtn = event.target.closest('[data-import-delete-open]');
        if (deleteBtn) {
            event.preventDefault();
            openDeleteModal(deleteBtn);
            return;
        }

        const assignBtn = event.target.closest('[data-import-assign-open]');
        if (assignBtn) {
            event.preventDefault();
            openAssignModal(assignBtn);
        }
    });
    deleteModal?.querySelectorAll('[data-import-delete-dismiss]').forEach((el) => {
        el.addEventListener('click', closeDeleteModal);
    });
    assignModal?.querySelectorAll('[data-import-assign-dismiss]').forEach((el) => {
        el.addEventListener('click', closeAssignModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDeleteModal();
            closeAssignModal();
        }
    });
})();
</script>
@endpush
