<div class="ghl-inbox-dialer-full">
    <div class="ghl-inbox-settings-header mb-4">
        <h2 class="text-lg font-bold text-zinc-900">Phone dialer</h2>
        <a href="{{ route($routePrefix . 'communications.index', request()->except(['panel'])) }}" class="comm-hub-link">←
            Back to inbox</a>
    </div>

    <div class="ghl-dialer-layout">
        <aside class="ghl-dialer-side">
            <h3 class="text-sm font-bold text-zinc-900">Recent numbers</h3>
            <div class="ghl-dialer-recent mt-3">
                @forelse($recentNumbers ?? [] as $number)
                    <button type="button" class="ghl-dialer-recent-btn"
                        data-dial-number="{{ $number }}">{{ $number }}</button>
                @empty
                    <p class="ghl-empty py-4">No recent numbers yet.</p>
                @endforelse
            </div>
        </aside>

        <section class="ghl-dialer-panel">
            @include('communications.partials.dialer-form', [
                'routePrefix' => $routePrefix,
                'callerSelectId' => 'dial-caller-id-full',
                'numberInputId' => 'dial-number-full',
                'dialBtnId' => 'morpheus-dial-btn-full',
                'backspaceId' => 'dial-backspace-full',
                'keypadRootId' => 'dial-keypad-full',
                'prefillNumber' => $prefillNumber ?? '',
                'phoneUsers' => $phoneUsers ?? [],
                'morpheusExtensions' => $morpheusExtensions ?? [],
                'defaultCallerId' => $defaultCallerId ?? null,
                'clickToCall' => $clickToCall ?? null,
            ])
        </section>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.initGhlDialer?.({
            numberInputId: 'dial-number-full',
            callerSelectId: 'dial-caller-id-full',
            dialBtnId: 'morpheus-dial-btn-full',
            backspaceId: 'dial-backspace-full',
            keypadRootId: 'dial-keypad-full',
        });
    });
</script>
