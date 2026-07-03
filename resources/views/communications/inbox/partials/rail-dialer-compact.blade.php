@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $extensions = $morpheusExtensions ?? [];
    if ($extensions === [] && !empty($phoneUsers)) {
        foreach ($phoneUsers as $user) {
            $extensions[] = [
                'id' => $user['id'] ?? null,
                'extension_num' => $user['extension_number'] ?? ($user['phone_numbers'][0] ?? null),
                'caller_id_name' => $user['name'] ?? null,
                'caller_id_num' => $user['default_caller_id'] ?? null,
            ];
        }
    }
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
@endphp

<form method="POST" action="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
    class="ghl-dialer-originate-form ghl-dialer-compact">
    @csrf
    <input type="hidden" name="fallback" value="sip">

    <label class="comm-hub-label" for="dial-caller-id-rail">From</label>
    <select id="dial-caller-id-rail" name="from_extension" class="comm-hub-input comm-hub-input-sm ghl-dialer-field"
        required>
        <option value="" disabled @selected($defaultExtension === null || $defaultExtension === '')>Your extension</option>
        @foreach ($extensions as $ext)
            @php $extNum = $ext['extension_num'] ?? ''; @endphp
            @if (filled($extNum))
                <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)>
                    {{ $ext['caller_id_name'] ?? 'Extension' }} — {{ $extNum }}
                </option>
            @endif
        @endforeach
    </select>

    <label class="comm-hub-label" for="dial-number-rail">Number to dial</label>
    <input type="tel" id="dial-number-rail" name="destination"
        class="comm-hub-input comm-hub-input-sm ghl-dialer-display ghl-dialer-field" value="{{ $prefillNumber ?? '' }}"
        placeholder="Enter number" required autocomplete="tel">

    <div class="ghl-dialer-keypad ghl-dialer-keypad-compact" id="dial-keypad-rail">
        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
            <button type="button" class="ghl-dialer-key ghl-dialer-key-sm"
                data-dial-key="{{ $key }}">{{ $key }}</button>
        @endforeach
    </div>

    <button type="submit" id="morpheus-dial-btn-rail" class="comm-hub-btn ghl-dialer-call-btn ghl-call-btn">Call</button>
</form>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        window.initGhlDialer?.({
            numberInputId: 'dial-number-rail',
            callerSelectId: 'dial-caller-id-rail',
            dialBtnId: 'morpheus-dial-btn-rail',
            keypadRootId: 'dial-keypad-rail',
        });
    });
</script>
