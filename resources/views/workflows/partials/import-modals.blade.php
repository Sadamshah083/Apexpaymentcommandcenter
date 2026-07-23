@php
    use App\Support\SalesOps;
    use App\Support\WorkflowAssignmentRoles;

    $teamLeads = collect($setterTeamLeads ?? $teamLeads ?? []);
    $closerTeamLeadRole = WorkflowAssignmentRoles::closerTeamLeadRole();
    $activeSetters = collect($activeSetters ?? []);
    $setterTeamMemberMap = $setterTeamMemberMap ?? [];
    $campaignNames = collect($campaignNames ?? []);
    $activeSetterCount = (int) ($activeSetterCount ?? $activeSetters->count());
    $activeSettersPayload = $activeSetters->map(static function ($user) {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'team_lead_user_id' => (int) ($user->pivot->team_lead_user_id ?? 0),
            'role' => (string) ($user->pivot->role ?? ''),
        ];
    })->values()->all();

    $teamLeadOptionLabel = static function ($teamLead) use ($campaignNames) {
        $campaignId = (int) ($teamLead->pivot->campaign_id ?? 0);
        $campaignLabel = $campaignId > 0
            ? (string) ($campaignNames[$campaignId] ?? '')
            : '';
        $roleLabel = SalesOps::roleLabel((string) ($teamLead->pivot->role ?? ''));
        $optionLabel = (string) $teamLead->name.' ('.$roleLabel.')';
        if ($campaignLabel !== '') {
            $optionLabel = $campaignLabel.' — '.$optionLabel;
        }

        return $optionLabel;
    };
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
        <div class="assign-leads-panel__header">
            <h2 id="import-assign-title" class="member-confirm-title text-left">Assign leads</h2>
            <p id="import-assign-desc" class="member-confirm-message text-left mb-0">Assign enriched leads to a team lead. Leads are distributed across that team’s active members.</p>
        </div>

        <div class="assign-leads-panel__body">
            <div id="import-assign-stats" class="assign-leads-stats"></div>

            <div id="import-assign-alert" class="hidden mb-4 rounded-lg border px-3 py-2 text-sm" role="alert"></div>

            @if ($activeSetterCount === 0)
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    No active setters or closers in this workspace. Add team member accounts under User Management before assigning leads.
                </div>
            @endif

            <form id="import-assign-form" method="POST" action="#" class="assign-leads-form text-left">
                @csrf
                <div class="assign-leads-panel__fields">
                    <div class="app-field">
                        <label for="import-assign-team-lead" class="app-label">Team lead</label>
                        <select
                            name="team_lead_id"
                            id="import-assign-team-lead"
                            class="app-input w-full"
                            required
                            @disabled($teamLeads->isEmpty())
                        >
                            <option value="">Select team lead…</option>
                            @foreach($teamLeads as $teamLead)
                                <option
                                    value="{{ $teamLead->id }}"
                                    data-team-lead-role="{{ $teamLead->pivot->role }}"
                                    data-campaign-id="{{ (int) ($teamLead->pivot->campaign_id ?? 0) }}"
                                >{{ $teamLeadOptionLabel($teamLead) }}</option>
                            @endforeach
                        </select>
                        @if($teamLeads->isEmpty())
                            <p class="text-xs text-amber-700 mt-1">Add an Appointment Setter Team Lead or Closers Team Lead under User Management before assigning.</p>
                        @else
                            <p class="text-xs text-zinc-500 mt-1">Select a team lead to show their team members below.</p>
                        @endif
                    </div>
                    <div class="app-field" id="import-assign-members-field">
                        <div class="assign-leads-members-head">
                            <label class="app-label mb-0" id="import-assign-members-label" for="import-assign-members">Team members</label>
                            <div class="assign-leads-members-toolbar" id="import-assign-members-toolbar" hidden>
                                <button type="button" class="assign-leads-members-toggle" id="import-assign-members-all">Select all</button>
                                <span class="assign-leads-members-toolbar-sep" aria-hidden="true">·</span>
                                <button type="button" class="assign-leads-members-toggle" id="import-assign-members-none">Unselect all</button>
                            </div>
                        </div>
                        <div id="import-assign-members" class="assign-leads-members" role="group" aria-labelledby="import-assign-members-label">
                            <p class="assign-leads-members-empty" data-members-empty>Select a team lead to see their members.</p>
                        </div>
                        <p class="assign-leads-members-hint" id="import-assign-members-hint">
                            Leave all selected to split leads across every member on that team.
                        </p>
                    </div>
                    <div class="app-field">
                        <label for="import-assign-count" class="app-label">Number of leads</label>
                        <input type="number" name="lead_count" id="import-assign-count" class="app-input w-full" min="1" value="1" required>
                    </div>
                </div>
                <div class="member-confirm-actions assign-leads-panel__actions !justify-end">
                    <button type="button" class="member-confirm-cancel" data-import-assign-dismiss>Cancel</button>
                    <button type="submit" class="member-confirm-submit" id="import-assign-submit" @disabled($teamLeads->isEmpty() || $activeSetterCount === 0)>Assign leads</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    id="import-unassign-modal"
    class="member-confirm-modal"
    hidden
    aria-hidden="true"
    role="dialog"
    aria-labelledby="import-unassign-title"
    aria-modal="true"
>
    <div class="member-confirm-backdrop" data-import-unassign-dismiss></div>
    <div class="member-confirm-panel assign-leads-panel" role="document">
        <div class="assign-leads-panel__header">
            <h2 id="import-unassign-title" class="member-confirm-title text-left">Unassign leads</h2>
            <p id="import-unassign-desc" class="member-confirm-message text-left mb-0">Return assigned leads to the pool. Agents lose them immediately; you can assign them again anytime.</p>
        </div>

        <div class="assign-leads-panel__body">
            <div id="import-unassign-stats" class="assign-leads-stats"></div>
            <div id="import-unassign-alert" class="assign-leads-alert" hidden role="alert"></div>

            <form id="import-unassign-form" method="POST" action="#" class="assign-leads-form text-left">
                @csrf
                <div class="assign-leads-panel__fields">
                    <div>
                        <label for="import-unassign-count" class="block text-xs font-semibold text-zinc-600 mb-1">How many leads to unassign?</label>
                        <input type="number" name="lead_count" id="import-unassign-count" min="1" max="5000" step="1" required
                            class="w-full rounded-lg border border-zinc-200 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                        <p class="assign-leads-members-hint mt-1">Leaves enrichment and call history intact. Only clears agent ownership.</p>
                    </div>

                    <div>
                        <div class="assign-leads-members-head">
                            <span id="import-unassign-agents-label" class="block text-xs font-semibold text-zinc-600">From which agents?</span>
                            <div class="assign-leads-members-toolbar" id="import-unassign-agents-toolbar" hidden>
                                <button type="button" class="assign-leads-members-toggle" id="import-unassign-agents-all">Select all</button>
                                <span class="assign-leads-members-toolbar-sep" aria-hidden="true">·</span>
                                <button type="button" class="assign-leads-members-toggle" id="import-unassign-agents-none">Unselect all</button>
                            </div>
                        </div>
                        <div id="import-unassign-agents" class="assign-leads-members" role="group" aria-labelledby="import-unassign-agents-label">
                            <p class="assign-leads-members-empty" data-unassign-agents-empty>No assigned agents on this import.</p>
                        </div>
                        <p class="assign-leads-members-hint" id="import-unassign-agents-hint">
                            Leave all selected to pull from every agent. Uncheck agents you want to keep assigned.
                        </p>
                    </div>
                </div>

                <div class="member-confirm-actions assign-leads-panel__actions !justify-end">
                    <button type="button" class="member-confirm-cancel" data-import-unassign-dismiss>Cancel</button>
                    <button type="submit" class="member-confirm-submit bg-amber-600 hover:bg-amber-700" id="import-unassign-submit">Unassign leads</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    id="import-edit-modal"
    class="member-confirm-modal"
    hidden
    aria-hidden="true"
    role="dialog"
    aria-labelledby="import-edit-title"
    aria-modal="true"
>
    <div class="member-confirm-backdrop" data-import-edit-dismiss></div>
    <div class="member-confirm-panel" role="document">
        <h2 id="import-edit-title" class="member-confirm-title text-left">Edit import</h2>
        <p class="member-confirm-message text-left mb-4">Update the import display name and file label. This does not rename the file on disk.</p>
        <form id="import-edit-form" method="POST" action="#" class="space-y-4 text-left">
            @csrf
            @method('PUT')
            <div class="app-field">
                <label for="import-edit-name" class="app-label">Import name</label>
                <input type="text" id="import-edit-name" name="name" class="app-input w-full" required maxlength="255" autocomplete="off">
            </div>
            <div class="app-field">
                <label for="import-edit-filename" class="app-label">File name</label>
                <input type="text" id="import-edit-filename" name="original_filename" class="app-input w-full" required maxlength="255" autocomplete="off">
            </div>
            <div class="member-confirm-actions !justify-end">
                <button type="button" class="member-confirm-cancel" data-import-edit-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit" id="import-edit-submit">Save changes</button>
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
    assignMembersAll?.addEventListener('click', () => setMemberChecks(true));
    assignMembersNone?.addEventListener('click', () => setMemberChecks(false));

    const editModal = document.getElementById('import-edit-modal');
    const editForm = document.getElementById('import-edit-form');
    const editName = document.getElementById('import-edit-name');
    const editFilename = document.getElementById('import-edit-filename');
    let editRow = null;

    function openEditModal(btn) {
        if (!editModal || !editForm || !editName || !editFilename) return;
        editRow = btn.closest('tr');
        editForm.action = btn.dataset.editUrl || '#';
        editName.value = btn.dataset.workflowName || '';
        editFilename.value = btn.dataset.workflowFilename || '';
        editModal.hidden = false;
        editModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        editName.focus();
    }

    function closeEditModal() {
        if (!editModal) return;
        editModal.hidden = true;
        editModal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.member-confirm-modal:not([hidden])')) {
            document.body.classList.remove('member-confirm-open');
        }
        editRow = null;
    }

    const unassignModal = document.getElementById('import-unassign-modal');
    const unassignForm = document.getElementById('import-unassign-form');
    const unassignTitle = document.getElementById('import-unassign-title');
    const unassignStats = document.getElementById('import-unassign-stats');
    const unassignCount = document.getElementById('import-unassign-count');
    const unassignAlert = document.getElementById('import-unassign-alert');
    const unassignSubmit = document.getElementById('import-unassign-submit');
    const unassignAgents = document.getElementById('import-unassign-agents');
    const unassignAgentsToolbar = document.getElementById('import-unassign-agents-toolbar');
    const unassignAgentsAll = document.getElementById('import-unassign-agents-all');
    const unassignAgentsNone = document.getElementById('import-unassign-agents-none');
    let activeUnassignWorkflowId = null;

    function showUnassignAlert(message, type = 'error') {
        if (!unassignAlert) return;
        unassignAlert.hidden = false;
        unassignAlert.className = `assign-leads-alert assign-leads-alert--${type}`;
        unassignAlert.textContent = message;
    }

    function hideUnassignAlert() {
        if (!unassignAlert) return;
        unassignAlert.hidden = true;
        unassignAlert.textContent = '';
    }

    function parseAssignedAgents(raw) {
        if (!raw) return [];
        if (Array.isArray(raw)) return raw;
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }

    function selectedUnassignAgentIds() {
        return [...(unassignAgents?.querySelectorAll('input[name="agent_ids[]"]:checked') || [])]
            .map((input) => Number(input.value))
            .filter((id) => id > 0);
    }

    function selectedUnassignCapacity() {
        const checked = [...(unassignAgents?.querySelectorAll('input[name="agent_ids[]"]:checked') || [])];
        if (checked.length === 0) {
            return 0;
        }
        return checked.reduce((sum, input) => sum + Number(input.dataset.count || 0), 0);
    }

    function updateUnassignCountLimits() {
        const capacity = selectedUnassignCapacity();
        if (!unassignCount) return;
        unassignCount.max = String(Math.max(1, capacity || 1));
        if (capacity > 0) {
            const current = Number(unassignCount.value || 0);
            if (current < 1 || current > capacity) {
                unassignCount.value = String(capacity);
            }
        }
        if (unassignSubmit) {
            unassignSubmit.disabled = capacity < 1;
        }
    }

    function renderUnassignAgents(agents) {
        if (!unassignAgents) return;
        if (!agents.length) {
            unassignAgents.innerHTML = '<p class="assign-leads-members-empty" data-unassign-agents-empty>No assigned agents on this import.</p>';
            if (unassignAgentsToolbar) unassignAgentsToolbar.hidden = true;
            updateUnassignCountLimits();
            return;
        }

        unassignAgents.innerHTML = agents.map((agent) => {
            const id = Number(agent.user_id || agent.id || 0);
            const name = escapeHtml(agent.name || `Agent #${id}`);
            const count = Number(agent.count || 0);
            return `<label class="assign-leads-member-row">
                <input type="checkbox" name="agent_ids[]" value="${id}" data-count="${count}" checked class="assign-leads-member-check">
                <span class="assign-leads-member-name">${name}</span>
                <span class="assign-leads-member-meta">${count.toLocaleString()} leads</span>
            </label>`;
        }).join('');
        if (unassignAgentsToolbar) unassignAgentsToolbar.hidden = false;
        updateUnassignCountLimits();
    }

    function openUnassignModal(button) {
        if (!unassignModal || !unassignForm) return;
        const id = button.dataset.workflowId;
        const name = button.dataset.workflowName || 'Import';
        const total = Number(button.dataset.workflowTotal || 0);
        const assigned = Number(button.dataset.workflowAssigned || 0);
        const remaining = Number(button.dataset.workflowRemaining || 0);
        const agents = parseAssignedAgents(button.getAttribute('data-assigned-agents'));

        activeUnassignWorkflowId = id;
        hideUnassignAlert();
        unassignForm.action = `${assignBase}/${id}/unassign-leads`;
        if (unassignTitle) unassignTitle.textContent = `Unassign: ${name}`;
        if (unassignStats) {
            unassignStats.innerHTML = `
                <div class="assign-leads-stat"><span class="assign-leads-stat__label">Total</span><strong class="assign-leads-stat__value">${total.toLocaleString()}</strong></div>
                <div class="assign-leads-stat"><span class="assign-leads-stat__label">Assigned</span><strong class="assign-leads-stat__value assign-leads-stat__value--success">${assigned.toLocaleString()}</strong></div>
                <div class="assign-leads-stat"><span class="assign-leads-stat__label">Ready</span><strong class="assign-leads-stat__value assign-leads-stat__value--ready">${remaining.toLocaleString()}</strong></div>`;
        }
        renderUnassignAgents(agents);
        unassignModal.hidden = false;
        unassignModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        unassignCount?.focus();
    }

    function closeUnassignModal() {
        if (!unassignModal) return;
        unassignModal.hidden = true;
        unassignModal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.member-confirm-modal:not([hidden])')) {
            document.body.classList.remove('member-confirm-open');
        }
        activeUnassignWorkflowId = null;
        hideUnassignAlert();
    }

    function patchUnassignRowStats(workflowId, unassignedCount, remaining, stillAssigned) {
        const row = document.querySelector(`tr[data-workflow-id="${workflowId}"]`);
        if (!row) return;

        const assignBtn = row.querySelector('[data-import-assign-open]');
        const unassignBtn = row.querySelector('[data-import-unassign-open]');
        const remainingCell = row.cells[7];

        if (typeof stillAssigned === 'number') {
            if (assignBtn) assignBtn.dataset.workflowAssigned = String(stillAssigned);
            if (unassignBtn) unassignBtn.dataset.workflowAssigned = String(stillAssigned);
        } else if (unassignBtn) {
            const current = Number(unassignBtn.dataset.workflowAssigned || 0);
            unassignBtn.dataset.workflowAssigned = String(Math.max(0, current - unassignedCount));
        }

        if (typeof remaining === 'number') {
            if (remainingCell) remainingCell.textContent = Math.max(0, remaining).toLocaleString();
            if (assignBtn) assignBtn.dataset.workflowRemaining = String(Math.max(0, remaining));
            if (unassignBtn) unassignBtn.dataset.workflowRemaining = String(Math.max(0, remaining));
        }

        if (unassignBtn && Number(unassignBtn.dataset.workflowAssigned || 0) <= 0) {
            unassignBtn.remove();
            const actions = row.querySelector('.import-assign-actions');
            if (actions && !actions.querySelector('[data-import-assign-open]') && !actions.querySelector('[data-import-unassign-open]')) {
                actions.innerHTML = '<span class="import-assign-empty">&mdash;</span>';
            }
        }
    }

    unassignAgentsAll?.addEventListener('click', () => {
        unassignAgents?.querySelectorAll('input[name="agent_ids[]"]').forEach((input) => { input.checked = true; });
        updateUnassignCountLimits();
    });
    unassignAgentsNone?.addEventListener('click', () => {
        unassignAgents?.querySelectorAll('input[name="agent_ids[]"]').forEach((input) => { input.checked = false; });
        updateUnassignCountLimits();
    });
    unassignAgents?.addEventListener('change', (event) => {
        if (event.target?.matches('input[name="agent_ids[]"]')) {
            updateUnassignCountLimits();
        }
    });

    unassignForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideUnassignAlert();

        const agentIds = selectedUnassignAgentIds();
        if (!activeUnassignWorkflowId) {
            showUnassignAlert('Choose an import before unassigning leads.');
            return;
        }
        if (agentIds.length === 0) {
            showUnassignAlert('Select at least one agent to unassign from.');
            return;
        }

        const capacity = selectedUnassignCapacity();
        const requested = Number(unassignCount?.value || 0);
        if (requested < 1 || requested > capacity) {
            showUnassignAlert(`Enter a lead count between 1 and ${capacity.toLocaleString()}.`);
            return;
        }

        const originalText = unassignSubmit?.textContent || 'Unassign leads';
        if (unassignSubmit) {
            unassignSubmit.disabled = true;
            unassignSubmit.textContent = 'Unassigning…';
        }

        try {
            const token = unassignForm.querySelector('input[name="_token"]')?.value || '';
            const body = new FormData();
            body.append('_token', token);
            body.append('lead_count', String(requested));
            agentIds.forEach((id) => body.append('agent_ids[]', String(id)));

            const res = await fetch(unassignForm.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body,
            });
            const payload = await res.json().catch(() => ({}));
            if (!res.ok) {
                const message = payload.message || Object.values(payload.errors || {}).flat()[0] || 'Could not unassign leads.';
                showUnassignAlert(message, 'error');
                return;
            }

            const unassigned = Number(payload.unassigned || requested);
            const remaining = typeof payload.remaining === 'number' ? payload.remaining : null;
            const stillAssigned = typeof payload.assigned === 'number' ? payload.assigned : null;
            showUnassignAlert(payload.message || `Unassigned ${unassigned} lead(s).`, 'success');
            patchUnassignRowStats(activeUnassignWorkflowId, unassigned, remaining, stillAssigned);
            window.showToast?.(payload.message || `Unassigned ${unassigned} lead(s).`, 'success');
            window.setTimeout(() => closeUnassignModal(), 900);
        } catch (err) {
            showUnassignAlert(err.message || 'Could not unassign leads.', 'error');
        } finally {
            if (unassignSubmit) {
                unassignSubmit.disabled = false;
                unassignSubmit.textContent = originalText;
                updateUnassignCountLimits();
            }
        }
    });

    editForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const submitBtn = document.getElementById('import-edit-submit');
        const original = submitBtn?.textContent;
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving…';
        }
        try {
            const token = editForm.querySelector('input[name="_token"]')?.value || '';
            const res = await fetch(editForm.action, {
                method: 'PUT',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    name: editName.value,
                    original_filename: editFilename.value,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                throw new Error(data.message || 'Could not save import.');
            }
            if (editRow) {
                const nameEl = editRow.querySelector('[data-import-name]');
                const fileEl = editRow.querySelector('[data-import-filename]');
                const editBtn = editRow.querySelector('[data-import-edit-open]');
                if (nameEl) nameEl.textContent = data.name || editName.value;
                if (fileEl) {
                    fileEl.textContent = data.original_filename || editFilename.value;
                    fileEl.setAttribute('title', data.original_filename || editFilename.value);
                }
                if (editBtn) {
                    editBtn.dataset.workflowName = data.name || editName.value;
                    editBtn.dataset.workflowFilename = data.original_filename || editFilename.value;
                }
            }
            window.showToast?.(data.message || 'Import updated.', 'success');
            closeEditModal();
        } catch (err) {
            window.showToast?.(err.message || 'Could not save import.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = original || 'Save changes';
            }
        }
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
            return;
        }

        const unassignBtn = event.target.closest('[data-import-unassign-open]');
        if (unassignBtn) {
            event.preventDefault();
            openUnassignModal(unassignBtn);
            return;
        }

        const editBtn = event.target.closest('[data-import-edit-open]');
        if (editBtn) {
            event.preventDefault();
            openEditModal(editBtn);
        }
    });
    deleteModal?.querySelectorAll('[data-import-delete-dismiss]').forEach((el) => {
        el.addEventListener('click', closeDeleteModal);
    });
    assignModal?.querySelectorAll('[data-import-assign-dismiss]').forEach((el) => {
        el.addEventListener('click', closeAssignModal);
    });
    unassignModal?.querySelectorAll('[data-import-unassign-dismiss]').forEach((el) => {
        el.addEventListener('click', closeUnassignModal);
    });
    editModal?.querySelectorAll('[data-import-edit-dismiss]').forEach((el) => {
        el.addEventListener('click', closeEditModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDeleteModal();
            closeAssignModal();
            closeUnassignModal();
            closeEditModal();
        }
    });
})();
</script>
@endpush
