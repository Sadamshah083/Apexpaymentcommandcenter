<label class="comm-hub-label block mb-1 text-xs" for="dial-caller-id-rail">From</label>
<select id="dial-caller-id-rail" class="comm-hub-input w-full mb-2 comm-hub-input-sm">
    <option value="">Default line</option>
    @foreach($phoneUsers ?? [] as $user)
        @foreach($user['phone_numbers'] as $number)
            <option value="{{ $number }}" @selected(($defaultCallerId ?? '') === $number)>{{ $user['name'] }}</option>
        @endforeach
    @endforeach
</select>

<input type="tel" id="dial-number-rail" class="comm-hub-input w-full mb-2 comm-hub-input-sm"
       value="{{ $prefillNumber ?? '' }}" placeholder="Number to dial">

<div class="ghl-dialer-keypad ghl-dialer-keypad-compact mb-2" id="dial-keypad-rail">
    @foreach(['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
        <button type="button" class="ghl-dialer-key ghl-dialer-key-sm" data-dial-key="{{ $key }}">{{ $key }}</button>
    @endforeach
</div>

<a href="#" id="zoom-dial-btn-rail" class="comm-hub-btn ghl-call-btn w-full text-center block" data-zoom-call="1">Call</a>

<script>
document.addEventListener('DOMContentLoaded', function () {
    window.initGhlDialer?.({
        numberInputId: 'dial-number-rail',
        callerSelectId: 'dial-caller-id-rail',
        dialBtnId: 'zoom-dial-btn-rail',
        keypadRootId: 'dial-keypad-rail',
    });
});
</script>
