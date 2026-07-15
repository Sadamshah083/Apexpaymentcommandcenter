@php
    $extensions = $morpheusExtensions ?? [];
    if ($extensions === [] && !empty($phoneUsers) && str_starts_with($routePrefix ?? '', 'admin.')) {
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
    $defaultOutboundDid = config('integrations.communications.default_outbound_did');
    $formId = $formId ?? null;
    $lineDidResolver = app(\App\Services\Communications\CommunicationsAgentService::class);

    $lineOptions = collect($extensions)
        ->map(function ($ext) use ($defaultOutboundDid, $lineDidResolver) {
            $extNum = $ext['extension_num'] ?? '';
            if (!filled($extNum)) {
                return null;
            }

            $resolvedDid = $lineDidResolver->resolveOutboundDid(
                (string) $extNum,
                filled($ext['outbound_cid_num'] ?? null)
                    ? (string) $ext['outbound_cid_num']
                    : (filled($ext['caller_id_num'] ?? null) ? (string) $ext['caller_id_num'] : null),
            );

            return [
                'extension' => (string) $extNum,
                'did' => (string) ($resolvedDid ?? $defaultOutboundDid ?? ''),
                'outbound_did' => $resolvedDid ?? $ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? $defaultOutboundDid,
            ];
        })
        ->filter()
        ->values();

    if ($lineOptions->isEmpty() && filled($defaultExtension)) {
        $resolvedDid = $lineDidResolver->resolveOutboundDid((string) $defaultExtension);
        $lineOptions = collect([[
            'extension' => (string) $defaultExtension,
            'did' => (string) ($resolvedDid ?? $defaultOutboundDid ?? ''),
            'outbound_did' => $resolvedDid ?? $defaultOutboundDid,
        ]]);
    }

    $selectedOption = $lineOptions->first(
        fn ($line) => (string) $defaultExtension === (string) $line['extension'],
    ) ?? $lineOptions->first();
    $triggerStyle = $triggerStyle ?? 'default';
    $isToolbarTrigger = $triggerStyle === 'toolbar';
@endphp

<div class="ghl-dialer-ext-field {{ $isToolbarTrigger ? 'ghl-dialer-ext-field--toolbar' : '' }}">
    <label class="ghl-dialer-ext-field__label" for="{{ $callerSelectId }}">Line</label>
    <div class="ghl-line-dropdown {{ $isToolbarTrigger ? 'ghl-line-dropdown--toolbar' : '' }}"
        data-line-dropdown data-line-trigger-style="{{ $triggerStyle }}">
        <select id="{{ $callerSelectId }}" name="from_extension"
            class="ghl-line-dropdown__native" @if ($formId) form="{{ $formId }}" @endif required
            @disabled($lineOptions->isEmpty()) aria-label="Select phone line">
            <option value="" disabled @selected($selectedOption === null)>Select line</option>
            @foreach ($lineOptions as $line)
                <option value="{{ $line['extension'] }}" @selected($selectedOption && $selectedOption['extension'] === $line['extension'])
                    data-outbound-did="{{ $line['outbound_did'] }}">
                    {{ $line['extension'] }}@if ($line['did'] !== '') · {{ $line['did'] }}@endif
                </option>
            @endforeach
        </select>

        <button type="button" class="ghl-line-dropdown__trigger" aria-haspopup="listbox"
            aria-expanded="false" @disabled($lineOptions->isEmpty())>
            <span class="ghl-line-dropdown__trigger-content">
                @if ($selectedOption)
                    @if ($isToolbarTrigger)
                        <span class="ghl-line-dropdown__ext-value">Ext {{ $selectedOption['extension'] }}</span>
                        @if ($selectedOption['did'] !== '')
                            <span class="ghl-line-dropdown__did">{{ $selectedOption['did'] }}</span>
                        @endif
                    @else
                        <span class="ghl-line-dropdown__ext-badge">Ext {{ $selectedOption['extension'] }}</span>
                        @if ($selectedOption['did'] !== '')
                            <span class="ghl-line-dropdown__did">{{ $selectedOption['did'] }}</span>
                        @endif
                    @endif
                @else
                    <span class="ghl-line-dropdown__placeholder">Select line</span>
                @endif
            </span>
            <svg class="ghl-line-dropdown__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div class="ghl-line-dropdown__menu" hidden>
            @if ($lineOptions->count() > 6)
                <div class="ghl-line-dropdown__search-wrap">
                    <svg class="ghl-line-dropdown__search-icon" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path stroke-linecap="round" d="M20 20l-3-3"></path>
                    </svg>
                    <input type="search" id="{{ ($callerSelectId ?? 'line') }}-search"
                        name="line_search"
                        class="ghl-line-dropdown__search" placeholder="Search extension or number"
                        autocomplete="off" spellcheck="false" aria-label="Search phone lines">
                </div>
            @endif
            <div class="ghl-line-dropdown__list" role="listbox" aria-label="Phone lines">
                @foreach ($lineOptions as $line)
                    <button type="button"
                        class="ghl-line-dropdown__option {{ $selectedOption && $selectedOption['extension'] === $line['extension'] ? 'is-selected' : '' }}"
                        role="option" data-line-option data-value="{{ $line['extension'] }}"
                        data-did="{{ $line['did'] }}"
                        data-search="{{ strtolower($line['extension'] . ' ' . $line['did']) }}"
                        aria-selected="{{ $selectedOption && $selectedOption['extension'] === $line['extension'] ? 'true' : 'false' }}">
                        <span class="ghl-line-dropdown__ext-badge">Ext {{ $line['extension'] }}</span>
                        @if ($line['did'] !== '')
                            <span class="ghl-line-dropdown__option-did">{{ $line['did'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>
</div>
