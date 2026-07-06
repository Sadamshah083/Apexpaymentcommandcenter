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
    <div class="comm-hub-alert comm-hub-alert-warning ghl-dialer-alert">
        <p class="ghl-dialer-alert-title">No phone line assigned yet</p>
        <p class="ghl-dialer-alert-desc">Ask an admin to provision your extension in Communications Hub → Phone Agents
            before you can place outbound calls.</p>
    </div>
@elseif (!$hasOutboundDid)
    <div class="comm-hub-alert comm-hub-alert-warning ghl-dialer-alert">
        <p class="ghl-dialer-alert-title">Outbound DID not configured</p>
        <p class="ghl-dialer-alert-desc">Your extension is ready, but no caller ID number is set. Once Morpheus delivers
            your DIDs, an admin must add the number under Phone Agents → Edit → Caller ID number.</p>
    </div>
@elseif (!$endpointOnline)
    <div class="comm-hub-alert comm-hub-alert-warning mb-4 text-sm">
        <p class="font-semibold">Your phone line is not online</p>
        <p class="mt-1">{{ $endpointHint ?? 'Register a SIP softphone or sign in to the Morpheus web phone before placing calls.' }}</p>
        <ul class="text-xs text-slate-600 list-disc pl-4 mt-2 space-y-1">
            <li>SIP server: <code>{{ $sipHost }}</code></li>
            <li>Extension: <strong>{{ $defaultExtension }}</strong> (password from Phone Agents)</li>
            <li>Apps: Zoiper, Linphone, or Morpheus web phone</li>
        </ul>
        @if ($portalUrl !== '#')
            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="comm-hub-link text-xs mt-2 inline-block">Open Morpheus web phone →</a>
        @endif
    </div>
@endif

<form method="POST" action="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
    class="ghl-dialer-originate-form {{ ($layout ?? '') === 'center' ? 'ghl-dialer-form--center' : '' }}" data-fallback-sip="1" data-originate-json="1"
    data-originate-json="1">
    @csrf
    <input type="hidden" name="fallback" value="sip">

    <label class="comm-hub-label" for="{{ $callerSelectId }}">Your extension (call from)</label>
    <select id="{{ $callerSelectId }}" name="from_extension" class="comm-hub-input ghl-dialer-field" required
        @disabled($extensions === [])>
        <option value="" disabled @selected($defaultExtension === null || $defaultExtension === '')>Select your extension
        </option>
        @foreach ($extensions as $ext)
            @php $extNum = $ext['extension_num'] ?? ''; @endphp
            @if (filled($extNum))
                <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)
                    data-outbound-did="{{ $ext['caller_id_num'] ?? $ext['outbound_cid_num'] ?? $defaultOutboundDid }}">
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

    <label class="comm-hub-label" for="{{ $numberInputId }}">Destination number</label>
    <input type="tel" id="{{ $numberInputId }}" name="destination"
        class="comm-hub-input ghl-dialer-display ghl-dialer-field" placeholder="+1 555 123 4567"
        value="{{ $prefillNumber ?? '' }}" required autocomplete="tel" @disabled($extensions === [])>

    <div class="ghl-dialer-keypad" id="{{ $keypadRootId }}">
        @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
            <button type="button" class="ghl-dialer-key" data-dial-key="{{ $key }}">{{ $key }}</button>
        @endforeach
    </div>

    <div class="ghl-dialer-actions">
        @if ($backspaceId)
            <button type="button" id="{{ $backspaceId }}" class="comm-hub-btn comm-hub-btn-secondary" data-dial-backspace>Delete</button>
        @endif
        <button type="submit" id="{{ $dialBtnId }}" class="comm-hub-btn ghl-dialer-call-btn"
            @disabled($extensions === [])>{{ ($layout ?? '') === 'center' ? 'Call' : 'Call with Morpheus CX' }}</button>
    </div>
</form>
