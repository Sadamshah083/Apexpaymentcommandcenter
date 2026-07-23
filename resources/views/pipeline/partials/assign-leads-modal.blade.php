<div
    id="assign-leads-modal"
    class="member-confirm-modal"
    hidden
    aria-hidden="true"
    role="dialog"
    aria-labelledby="assign-leads-title"
    aria-modal="true"
>
    <div class="member-confirm-backdrop" data-assign-leads-dismiss></div>
    <div class="member-confirm-panel assign-leads-panel" role="document">
        <div class="assign-leads-panel__header">
            <h2 id="assign-leads-title" class="member-confirm-title text-left">Assign leads</h2>
            <p class="member-confirm-message text-left mb-0">
                Assign by count, or select specific leads in the table first.
                <span class="block mt-1 font-semibold text-zinc-800">{{ number_format($unassignedLeads) }} available</span>
            </p>
            <p class="text-sm text-emerald-700 mt-2 hidden" data-assign-selected-summary>
                <span data-assign-selected-count>0</span> lead(s) selected from the table will be assigned.
            </p>
        </div>

        <form method="POST" action="{{ $formAction }}" class="assign-leads-form text-left" id="assign-selected-leads-form" data-assign-leads-form>
            @csrf
            <div data-assign-selected-ids></div>
            <div class="assign-leads-panel__fields">
                <div class="app-field" data-assign-count-field>
                    <label for="assign-lead-count" class="app-label">Number of leads</label>
                    <input
                        type="number"
                        name="lead_count"
                        id="assign-lead-count"
                        class="app-input w-full"
                        min="1"
                        max="{{ max(1, $unassignedLeads) }}"
                        value="{{ min(1, max(1, $unassignedLeads)) }}"
                        @disabled($unassignedLeads < 1)
                    >
                </div>

                <div class="app-field">
                    <label for="assign-setter-id" class="app-label">Assign to setter</label>
                    <select name="setter_id" id="assign-setter-id" class="app-input w-full js-pretty-select" data-pretty-select data-pretty-select-width="trigger" required @disabled($unassignedLeads < 1)>
                        <option value="">Select setter…</option>
                        @foreach($setters as $setter)
                            @php
                                $metric = collect($teamMetrics)->first(fn ($row) => $row['user']->id === $setter->id);
                                $activeCount = $metric['active_leads'] ?? 0;
                            @endphp
                            <option value="{{ $setter->id }}">{{ $setter->name }} ({{ $activeCount }} active)</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="member-confirm-actions assign-leads-panel__actions !justify-end">
                <button type="button" class="member-confirm-cancel" data-assign-leads-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit" @disabled($unassignedLeads < 1)>Assign</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(() => {
    const modal = document.getElementById('assign-leads-modal');
    if (!modal || modal.dataset.assignInit === '1') return;
    modal.dataset.assignInit = '1';

    const form = modal.querySelector('[data-assign-leads-form]');
    const countInput = modal.querySelector('#assign-lead-count');
    const countField = modal.querySelector('[data-assign-count-field]');
    const selectedBox = modal.querySelector('[data-assign-selected-ids]');
    const selectedSummary = modal.querySelector('[data-assign-selected-summary]');
    const selectedCountEl = modal.querySelector('[data-assign-selected-count]');
    const openers = document.querySelectorAll('[data-assign-leads-open]');
    const dismissTargets = modal.querySelectorAll('[data-assign-leads-dismiss]');

    function selectedLeadIds() {
        return Array.from(document.querySelectorAll('[data-assign-lead-id]:checked'))
            .map((el) => Number(el.value || el.dataset.assignLeadId || 0))
            .filter((id) => id > 0);
    }

    function syncSelectionUi() {
        const ids = selectedLeadIds();
        if (selectedBox) {
            selectedBox.innerHTML = ids.map((id) => `<input type="hidden" name="lead_ids[]" value="${id}">`).join('');
        }
        if (selectedSummary && selectedCountEl) {
            selectedSummary.classList.toggle('hidden', ids.length === 0);
            selectedCountEl.textContent = String(ids.length);
        }
        if (countField) {
            countField.classList.toggle('opacity-50', ids.length > 0);
        }
        if (countInput) {
            if (ids.length > 0) {
                countInput.removeAttribute('required');
                countInput.disabled = true;
            } else {
                countInput.disabled = false;
                countInput.setAttribute('required', 'required');
            }
        }
        const assignSelectedBtn = document.querySelector('[data-assign-selected-open]');
        if (assignSelectedBtn) {
            assignSelectedBtn.disabled = ids.length === 0;
            assignSelectedBtn.textContent = ids.length > 0
                ? `Assign selected (${ids.length})`
                : 'Assign selected';
        }
    }

    function openModal() {
        syncSelectionUi();
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        window.initPrettySelects?.(modal);
        const ids = selectedLeadIds();
        (ids.length ? modal.querySelector('#assign-setter-id') : countInput)?.focus?.();
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('member-confirm-open');
    }

    openers.forEach((button) => button.addEventListener('click', openModal));
    dismissTargets.forEach((button) => button.addEventListener('click', closeModal));

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-assign-lead-id], [data-assign-select-all]')) {
            if (event.target.matches('[data-assign-select-all]')) {
                const checked = event.target.checked;
                document.querySelectorAll('[data-assign-lead-id]').forEach((box) => {
                    box.checked = checked;
                });
            }
            syncSelectionUi();
        }
    });

    form?.addEventListener('submit', () => {
        syncSelectionUi();
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal.querySelector('.member-confirm-backdrop')) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });

    syncSelectionUi();
})();
</script>
@endpush
