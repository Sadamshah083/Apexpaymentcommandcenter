@php
    use App\Support\SalesOps;
    use App\Support\WorkflowAssignmentRoles;

    $teamLeads = $setterTeamLeads ?? $teamLeads ?? collect();
    $setterTeamLeadRole = WorkflowAssignmentRoles::setterTeamLeadRole();
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
        <form id="import-delete-form" method="POST" action="#">
            @csrf
            @method('DELETE')
            <div class="member-confirm-actions">
                <button type="button" class="member-confirm-cancel" data-import-delete-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit bg-rose-600 hover:bg-rose-700">Delete permanently</button>
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
            <div class="app-field">
                <label for="import-assign-count" class="app-label">Number of leads</label>
                <input type="number" name="lead_count" id="import-assign-count" class="app-input w-full" min="1" max="500" value="1" required>
            </div>
            <div class="member-confirm-actions !justify-end">
                <button type="button" class="member-confirm-cancel" data-import-assign-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit">Assign leads</button>
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
    const assignBase = @json(url('/admin/workflows'));
    const setterTeamLeadRole = @json($setterTeamLeadRole);

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
    }

    function syncAssignTeamLeadDefault() {
        if (!assignTeamLead) return;
        const setterOption = [...assignTeamLead.options].find(
            (option) => option.dataset.teamLeadRole === setterTeamLeadRole,
        );
        if (setterOption) {
            assignTeamLead.value = setterOption.value;
        }
    }

    function openAssignModal(button) {
        if (!assignModal || !assignForm) return;
        const id = button.dataset.workflowId;
        const name = button.dataset.workflowName || 'Import';
        const total = Number(button.dataset.workflowTotal || 0);
        const assigned = Number(button.dataset.workflowAssigned || 0);
        const remaining = Number(button.dataset.workflowRemaining || 0);
        assignForm.action = `${assignBase}/${id}/assign-leads`;
        assignTitle.textContent = `Assign: ${name}`;
        assignDesc.textContent = remaining > 0
            ? `${remaining.toLocaleString()} lead(s) ready to assign. Choose an Appointment Setter Team Lead — leads are split across their setters.`
            : 'No unassigned leads remain in this import.';
        assignStats.innerHTML = `
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Total</span><strong>${total.toLocaleString()}</strong></div>
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Assigned</span><strong class="text-emerald-700">${assigned.toLocaleString()}</strong></div>
            <div class="rounded-lg border border-zinc-200 px-2 py-2"><span class="block text-zinc-500">Remaining</span><strong class="text-amber-700">${remaining.toLocaleString()}</strong></div>`;
        assignCount.max = Math.max(1, remaining);
        assignCount.value = remaining > 0 ? remaining : 1;
        syncAssignTeamLeadDefault();
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
    }

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
