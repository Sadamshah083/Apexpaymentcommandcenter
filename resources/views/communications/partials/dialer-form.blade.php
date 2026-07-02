@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $clickToCall = $clickToCall ?? app(\App\Services\Communications\ZoomClickToCallService::class);
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
    $portalUrl = $clickToCall->portalUrl();
@endphp

<div class="ghl-dialer-outbound-help comm-hub-card p-4 mb-4 text-sm text-slate-600">
    <p class="font-semibold text-slate-800 mb-1">Outbound calling via Morpheus CX</p>
    <p class="mb-2">Calls are placed from your <strong>Morpheus extension</strong>. Your SIP softphone (Zoiper, Linphone, Morpheus web phone) must be registered and online.</p>
    <p class="text-xs text-slate-500">The hub tries the Morpheus API when <code>MORPHEUS_DIAL_METHOD=api</code>, otherwise opens your softphone via a SIP link.</p>
    @if($portalUrl !== '#')
        <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="comm-hub-link text-xs mt-2 inline-block">Open Morpheus agent portal →</a>
    @endif
</div>

<form method="POST"
      action="{{ route($routePrefix.'communications.morpheus.calls.originate') }}"
      class="ghl-dialer-originate-form"
      data-fallback-sip="1">
    @csrf
    <input type="hidden" name="fallback" value="sip">

    <label class="comm-hub-label block mb-1" for="{{ $callerSelectId }}">Your extension (call from)</label>
    <select id="{{ $callerSelectId }}" name="from_extension" class="comm-hub-input w-full mb-4" required>
        <option value="" disabled @selected($defaultExtension === null || $defaultExtension === '')>Select your extension</option>
        @foreach($extensions as $ext)
            @php $extNum = $ext['extension_num'] ?? ''; @endphp
            @if(filled($extNum))
                <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)>
                    {{ $ext['caller_id_name'] ?? 'Extension' }} — {{ $extNum }}
                    @if(!empty($ext['caller_id_num'])) (CID {{ $ext['caller_id_num'] }}) @endif
                </option>
            @endif
        @endforeach
    </select>

    <label class="comm-hub-label block mb-1" for="{{ $numberInputId }}">Destination number</label>
    <input type="tel"
           id="{{ $numberInputId }}"
           name="destination"
           class="comm-hub-input ghl-dialer-display w-full mb-4"
           placeholder="+1 555 123 4567"
           value="{{ $prefillNumber ?? '' }}"
           required
           autocomplete="tel">

    <div class="ghl-dialer-keypad mb-4" id="{{ $keypadRootId }}">
        @foreach(['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
            <button type="button" class="ghl-dialer-key" data-dial-key="{{ $key }}">{{ $key }}</button>
        @endforeach
    </div>

    <div class="ghl-dialer-actions">
        @if($backspaceId)
            <button type="button" id="{{ $backspaceId }}" class="comm-hub-btn comm-hub-btn-secondary">Delete</button>
        @endif
        <button type="submit" id="{{ $dialBtnId }}" class="comm-hub-btn ghl-dialer-call-btn">Call with Morpheus CX</button>
    </div>
</form>
