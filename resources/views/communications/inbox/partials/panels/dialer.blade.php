<div class="ghl-inbox-dialer-full">
    <div class="ghl-inbox-settings-header mb-4">
        <h2 class="text-lg font-bold text-zinc-900">Phone dialer</h2>
        <a href="{{ route($routePrefix.'communications.index', request()->except(['panel'])) }}" class="comm-hub-link">← Back to inbox</a>
    </div>

    <div class="ghl-dialer-layout">
        <aside class="ghl-dialer-side">
            <h3 class="text-sm font-bold text-zinc-900">Recent numbers</h3>
            <div class="ghl-dialer-recent mt-3">
                @forelse($recentNumbers ?? [] as $number)
                    <button type="button" class="ghl-dialer-recent-btn" data-dial-number="{{ $number }}">{{ $number }}</button>
                @empty
                    <p class="ghl-empty py-4">No recent numbers yet.</p>
                @endforelse
            </div>
        </aside>

        <section class="ghl-dialer-panel">
            <label class="comm-hub-label block mb-1" for="dial-caller-id-full">Call from</label>
            <select id="dial-caller-id-full" class="comm-hub-input w-full mb-4">
                <option value="">Default Zoom Phone line</option>
                @foreach($phoneUsers ?? [] as $user)
                    @foreach($user['phone_numbers'] as $number)
                        <option value="{{ $number }}" @selected(($defaultCallerId ?? '') === $number)>{{ $user['name'] }} — {{ $number }}</option>
                    @endforeach
                @endforeach
            </select>

            <label class="comm-hub-label block mb-1" for="dial-number-full">Phone number</label>
            <input type="tel" id="dial-number-full" class="comm-hub-input ghl-dialer-display w-full mb-4"
                   value="{{ $prefillNumber ?? '' }}" placeholder="+1 555 123 4567">

            <div class="ghl-dialer-keypad mb-4" id="dial-keypad-full">
                @foreach(['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
                    <button type="button" class="ghl-dialer-key" data-dial-key="{{ $key }}">{{ $key }}</button>
                @endforeach
            </div>

            <div class="ghl-dialer-actions">
                <button type="button" id="dial-backspace-full" class="comm-hub-btn comm-hub-btn-secondary">Delete</button>
                <a href="#" id="zoom-dial-btn-full" class="comm-hub-btn ghl-dialer-call-btn" data-zoom-call="1">Call with Zoom Phone</a>
            </div>
        </section>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    window.initGhlDialer?.({
        numberInputId: 'dial-number-full',
        callerSelectId: 'dial-caller-id-full',
        dialBtnId: 'zoom-dial-btn-full',
        backspaceId: 'dial-backspace-full',
        keypadRootId: 'dial-keypad-full',
    });
});
</script>
