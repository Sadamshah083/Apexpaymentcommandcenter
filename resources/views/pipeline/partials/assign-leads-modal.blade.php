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
        <h2 id="assign-leads-title" class="member-confirm-title text-left">Assign leads</h2>
        <p class="member-confirm-message text-left mb-4">
            Choose how many unassigned leads to give a setter.
            <span class="block mt-1 font-semibold text-zinc-800">{{ number_format($unassignedLeads) }} available</span>
        </p>

        <form method="POST" action="{{ $formAction }}" class="space-y-4 text-left">
            @csrf
            <div class="app-field">
                <label for="assign-lead-count" class="app-label">Number of leads</label>
                <input
                    type="number"
                    name="lead_count"
                    id="assign-lead-count"
                    class="app-input"
                    min="1"
                    max="{{ max(1, $unassignedLeads) }}"
                    value="{{ min(1, max(1, $unassignedLeads)) }}"
                    required
                    @disabled($unassignedLeads < 1)
                >
            </div>

            <div class="app-field">
                <label for="assign-setter-id" class="app-label">Assign to setter</label>
                <select name="setter_id" id="assign-setter-id" class="app-input" required @disabled($unassignedLeads < 1)>
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

            <div class="member-confirm-actions !justify-end">
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
    if (!modal) return;

    const openers = document.querySelectorAll('[data-assign-leads-open]');
    const dismissTargets = modal.querySelectorAll('[data-assign-leads-dismiss]');

    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('member-confirm-open');
        modal.querySelector('#assign-lead-count')?.focus();
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('member-confirm-open');
    }

    openers.forEach((button) => button.addEventListener('click', openModal));
    dismissTargets.forEach((button) => button.addEventListener('click', closeModal));

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
})();
</script>
@endpush
