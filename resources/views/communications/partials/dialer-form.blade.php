@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $clickToCall = $clickToCall ?? app(\App\Services\Communications\ZoomClickToCallService::class);
    $extensions = $morpheusExtensions ?? [];
    if ($extensions === [] && !empty($phoneUsers) && str_starts_with($routePrefix, 'admin.')) {
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
    $selectedExt = collect($extensions)->first(
        fn ($ext) => (string) ($ext['extension_num'] ?? '') === (string) $defaultExtension,
    );
    $hasOutboundDid = filled($selectedExt['caller_id_num'] ?? $selectedExt['outbound_cid_num'] ?? null)
        || filled(config('integrations.communications.default_outbound_did'));
    $endpointOnline = (bool) ($selectedExt['endpoint_online'] ?? true);
    $endpointHint = $selectedExt['endpoint_hint'] ?? null;
    $sipHost = $clickToCall->publicSipHost();
    $portalUrl = $clickToCall->portalUrl();
    $defaultOutboundDid = config('integrations.communications.default_outbound_did');
    $layout = $layout ?? 'sidebar';
@endphp

@unless ($layout === 'center')
<div class="ghl-dialer-outbound-help">
    <p class="ghl-dialer-help-title">Outbound calling via Morpheus CX</p>
    <p class="ghl-dialer-help-desc">Outbound calls dial the destination directly from your connected browser line.
        Click <strong>Connect line</strong> in the Phone panel first, then enter a number and press
        <strong>Call</strong>. The destination phone rings — you do not need to answer on your side.</p>
    <ol class="ghl-dialer-help-steps">
        <li>Admin provisions your phone line in <strong>Phone Agents</strong></li>
        <li>Assign your outbound DID as <strong>Caller ID number</strong> when Morpheus delivers it</li>
        <li>Click <strong>Connect line</strong> in the Phone panel (SIP realm <code>{{ $sipHost }}</code>)</li>
        <li>Enter a number and call — the destination rings until they answer</li>
    </ol>
    @if ($portalUrl !== '#')
        <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="ghl-dialer-help-link">Open Morpheus agent
            portal →</a>
    @endif
</div>
@endunless

@if ($extensions === [])
    <x-communications.molecules.alert variant="warning" class="ghl-dialer-alert mb-4" title="No phone line assigned">
        Ask an admin to provision your extension in Communications Hub → Phone Agents before you can place outbound calls.
    </x-communications.molecules.alert>
@elseif (!$hasOutboundDid)
    <x-communications.molecules.alert variant="warning" class="ghl-dialer-alert mb-4" title="Outbound DID not configured">
        Your extension is ready, but no caller ID number is set. Once Morpheus delivers your DIDs, an admin must add the number under Phone Agents → Edit → Caller ID number.
    </x-communications.molecules.alert>
@elseif (!$endpointOnline && $layout !== 'center')
    <x-communications.molecules.alert variant="warning" class="mb-4" title="Your phone line is not online">
        {{ $endpointHint ?? 'Register a SIP softphone or sign in to the Morpheus web phone before placing calls.' }}
        @if ($portalUrl !== '#')
            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="comm-hub-link text-xs mt-2 inline-block">Open Morpheus web phone →</a>
        @endif
    </x-communications.molecules.alert>
@endif

@if ($layout === 'center')
    <x-communications.molecules.alert variant="info" class="mb-4">
        Connect your line, then press <strong>Call</strong>. Your browser places the call over SIP (like a softphone) — the destination phone rings directly.
    </x-communications.molecules.alert>
@endif

<form method="POST" action="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
    class="ghl-dialer-originate-form {{ ($layout ?? '') === 'center' ? 'ghl-dialer-form--center ghl-dialer-form--enterprise' : '' }}"
    data-fallback-sip="1" data-originate-json="1" data-dial-via-sip="1">
    @csrf
    <input type="hidden" name="fallback" value="sip">

    <label class="ch-label" for="{{ $callerSelectId }}">Your extension</label>
    <select id="{{ $callerSelectId }}" name="from_extension" class="ch-input ghl-dialer-field mb-3" required
        @disabled($extensions === [])>
        <option value="" disabled @selected($defaultExtension === null || $defaultExtension === '')>Select your extension
        </option>
        @foreach ($extensions as $ext)
            @php $extNum = $ext['extension_num'] ?? ''; @endphp
            @if (filled($extNum))
                <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)
                    data-outbound-did="{{ $ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? $defaultOutboundDid }}">
                    {{ $ext['caller_id_name'] ?? 'Extension' }} — {{ $extNum }}
                    @if (!empty($ext['caller_id_num']))
                        (DID {{ $ext['caller_id_num'] }})
                    @else
                        (no DID yet)
                    @endif
                </option>
            @endif
        @endforeach
    </select>

    <p class="ghl-dialer-outbound-route text-sm text-slate-600 mt-1 mb-2" data-dialer-route-summary>
        Outbound caller ID:
        <strong data-dialer-from-did>{{ $defaultOutboundDid ?: 'Not configured' }}</strong>
        · destination rings as this number on the callee's phone.
    </p>

    <label class="ch-label" for="{{ $numberInputId }}">Destination number</label>
    <input type="tel" id="{{ $numberInputId }}" name="destination"
        class="ch-input ghl-dialer-display ghl-dialer-field mb-3" placeholder="+1 555 123 4567"
        value="{{ $prefillNumber ?? '' }}" required autocomplete="tel" @disabled($extensions === [])>

    <div class="ghl-dialer-keypad mb-4" id="{{ $keypadRootId }}">
        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
            <button type="button" class="ghl-dialer-key" data-dial-key="{{ $key }}">{{ $key }}</button>
        @endforeach
    </div>

    <div class="ghl-dialer-actions">
        @if ($backspaceId)
            <button type="button" id="{{ $backspaceId }}" class="ch-btn ch-btn--secondary" data-dial-backspace>Delete</button>
        @endif
        <button type="submit" id="{{ $dialBtnId }}" class="ch-btn ch-btn--call ghl-dialer-call-btn"
            @disabled($extensions === [])>{{ ($layout ?? '') === 'center' ? 'Call' : 'Call with Morpheus CX' }}</button>
    </div>
</form>
