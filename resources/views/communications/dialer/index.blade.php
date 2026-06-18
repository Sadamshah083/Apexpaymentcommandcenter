@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Dialer')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'dialer', 'routePrefix' => $routePrefix])

    @if($warning)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
    @endif

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="ghl-dialer-layout">
        <aside class="ghl-dialer-side">
            <h2 class="text-sm font-bold text-zinc-900">Recent numbers</h2>
            <p class="text-xs text-zinc-500 mt-1 mb-3">From call history in the last {{ config('integrations.communications.default_days', 14) }} days</p>
            <div class="ghl-dialer-recent">
                @forelse($recentNumbers as $number)
                    <button type="button" class="ghl-dialer-recent-btn" data-dial-number="{{ $number }}">{{ $number }}</button>
                @empty
                    <p class="ghl-empty py-4">No recent numbers yet.</p>
                @endforelse
            </div>
            <p class="text-xs text-zinc-500 mt-4">
                Calls launch the Zoom Phone desktop or mobile app via <code>zoomphonecall://</code>.
                Install and sign in to Zoom Phone on this device first.
            </p>
        </aside>

        <section class="ghl-dialer-panel">
            <h2 class="text-lg font-bold text-slate-900 mb-1">Place a call</h2>
            <p class="text-sm text-slate-500 mb-4">Enter a number and choose which line to call from.</p>

            <label class="comm-hub-label block mb-1" for="dial-caller-id">Call from</label>
            <select id="dial-caller-id" class="comm-hub-input w-full mb-4">
                <option value="">Default Zoom Phone line</option>
                @foreach($phoneUsers as $user)
                    @foreach($user['phone_numbers'] as $number)
                        <option value="{{ $number }}" @selected(($defaultCallerId ?? '') === $number)>
                            {{ $user['name'] }} — {{ $number }}
                        </option>
                    @endforeach
                    @if(empty($user['phone_numbers']) && !empty($user['extension_number']))
                        <option value="{{ $user['extension_number'] }}" @selected(($defaultCallerId ?? '') === (string) $user['extension_number'])>
                            {{ $user['name'] }} — ext {{ $user['extension_number'] }}
                        </option>
                    @endif
                @endforeach
            </select>

            <label class="comm-hub-label block mb-1" for="dial-number">Phone number</label>
            <input
                type="tel"
                id="dial-number"
                class="comm-hub-input ghl-dialer-display w-full mb-4"
                placeholder="+1 555 123 4567"
                value="{{ $prefillNumber ?? '' }}"
                autocomplete="tel"
            >

            <div class="ghl-dialer-keypad" id="dial-keypad" aria-label="Dial pad">
                @foreach(['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
                    <button type="button" class="ghl-dialer-key" data-dial-key="{{ $key }}">{{ $key }}</button>
                @endforeach
            </div>

            <div class="ghl-dialer-actions">
                <button type="button" id="dial-backspace" class="comm-hub-btn comm-hub-btn-secondary">Delete</button>
                <a href="#" id="zoom-dial-btn" class="comm-hub-btn ghl-dialer-call-btn" data-zoom-call="1">Call with Zoom Phone</a>
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const storageKey = 'communications.dialer_caller_id';
    const numberInput = document.getElementById('dial-number');
    const callerSelect = document.getElementById('dial-caller-id');
    const dialBtn = document.getElementById('zoom-dial-btn');

    if (!numberInput || !dialBtn) {
        return;
    }

    const savedCaller = localStorage.getItem(storageKey);
    if (savedCaller && callerSelect) {
        const match = Array.from(callerSelect.options).find((option) => option.value === savedCaller);
        if (match) {
            callerSelect.value = savedCaller;
        }
    }

    function normalizePhone(value) {
        const digits = String(value || '').replace(/[^\d+]/g, '');
        if (!digits) {
            return '';
        }
        if (digits.startsWith('+')) {
            return digits;
        }
        const numeric = digits.replace(/^0+/, '');
        if (numeric.length === 10) {
            return '+1' + numeric;
        }
        return '+' + numeric;
    }

    function buildDialUrl() {
        const target = normalizePhone(numberInput.value);
        if (!target) {
            return null;
        }

        let url = 'zoomphonecall://' + target;
        const caller = callerSelect ? normalizePhone(callerSelect.value) : '';
        if (caller) {
            url += '?callerid=' + encodeURIComponent(caller);
        }

        return url;
    }

    function refreshDialButton() {
        const url = buildDialUrl();
        if (!url) {
            dialBtn.setAttribute('href', '#');
            dialBtn.setAttribute('aria-disabled', 'true');
            dialBtn.classList.add('opacity-50', 'pointer-events-none');
            return;
        }

        dialBtn.setAttribute('href', url);
        dialBtn.removeAttribute('aria-disabled');
        dialBtn.classList.remove('opacity-50', 'pointer-events-none');
    }

    numberInput.addEventListener('input', refreshDialButton);
    if (callerSelect) {
        callerSelect.addEventListener('change', function () {
            localStorage.setItem(storageKey, callerSelect.value || '');
            refreshDialButton();
        });
    }

    document.querySelectorAll('[data-dial-key]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value += button.getAttribute('data-dial-key');
            refreshDialButton();
            numberInput.focus();
        });
    });

    document.querySelectorAll('[data-dial-number]').forEach((button) => {
        button.addEventListener('click', function () {
            numberInput.value = button.getAttribute('data-dial-number') || '';
            refreshDialButton();
        });
    });

    const backspace = document.getElementById('dial-backspace');
    if (backspace) {
        backspace.addEventListener('click', function () {
            numberInput.value = numberInput.value.slice(0, -1);
            refreshDialButton();
        });
    }

    refreshDialButton();
})();
</script>
@endpush
