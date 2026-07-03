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
    $hasOutboundDid = filled($selectedExt['caller_id_num'] ?? $selectedExt['outbound_cid_num'] ?? null);
    $sipHost = config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host');
@endphp

<div class="ghl-dialer-outbound-help">
    <p class="ghl-dialer-help-title">Outbound calling via Morpheus CX</p>
    <p class="ghl-dialer-help-desc">Calls ring your <strong>Morpheus extension</strong> first, then connect the
        destination. Register a SIP softphone (Zoiper, Linphone, or Morpheus web phone) to
        <code>{{ $sipHost }}</code> before dialing.</p>
    <ol class="ghl-dialer-help-steps">
        <li>Admin provisions your phone line in <strong>Phone Agents</strong></li>
        <li>Assign your outbound DID as <strong>Caller ID number</strong> when Morpheus delivers it</li>
        <li>Register softphone with your extension + SIP password</li>
        <li>Dial from this panel — API click-to-call when available, otherwise SIP softphone</li>
    </ol>
    @if ($portalUrl !== '#')
        <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="ghl-dialer-help-link">Open Morpheus agent
            portal →</a>
    @endif
</div>

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
@endif

<form method="POST" action="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
    class="ghl-dialer-originate-form" data-fallback-sip="1">
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
                <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)>
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
            <button type="button" id="{{ $backspaceId }}" class="comm-hub-btn comm-hub-btn-secondary">Delete</button>
        @endif
        <button type="submit" id="{{ $dialBtnId }}" class="comm-hub-btn ghl-dialer-call-btn"
            @disabled($extensions === [])>Call with Morpheus CX</button>
    </div>
</form>
