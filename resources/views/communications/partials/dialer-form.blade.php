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
    $defaultOutboundDid = config('integrations.communications.default_outbound_did');
    $lineDidResolver = app(\App\Services\Communications\CommunicationsAgentService::class);
    $apiDid = $selectedExt['outbound_cid_num'] ?? $selectedExt['caller_id_num'] ?? null;
    $resolvedDid = $lineDidResolver->resolveOutboundDid(
        filled($defaultExtension) ? (string) $defaultExtension : null,
        filled($apiDid) ? (string) $apiDid : null,
    ) ?? ($defaultOutboundDid ?: 'Not set');
    $layout = $layout ?? 'sidebar';
    $isCompactDial = in_array($layout, ['center', 'right-rail'], true);
    $hideExtension = (bool) ($hideExtension ?? false);
    $formId = $formId ?? ($isCompactDial ? ($callerSelectId . '-form') : null);
    $dialValue = filled($prefillNumber ?? null) ? $prefillNumber : '';
@endphp

@unless ($layout === 'center' || $layout === 'right-rail')
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
@elseif (!$endpointOnline && !$isCompactDial)
    <x-communications.molecules.alert variant="warning" class="mb-4" title="Your phone line is not online">
        {{ $endpointHint ?? 'Register a SIP softphone or sign in to the Morpheus web phone before placing calls.' }}
        @if ($portalUrl !== '#')
            <a href="{{ $portalUrl }}" target="_blank" rel="noopener" class="comm-hub-link text-xs mt-2 inline-block">Open Morpheus web phone →</a>
        @endif
    </x-communications.molecules.alert>
@endif

<form method="POST" action="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
    @if ($formId) id="{{ $formId }}" @endif
    class="ghl-dialer-originate-form {{ $isCompactDial ? 'ghl-dialer-form--center ghl-dialer-form--enterprise ghl-dialer-form--compact ghl-dialer-form--phone' : '' }} {{ ($layout ?? '') === 'right-rail' ? 'ghl-dialer-form--right-rail' : '' }}"
    data-fallback-sip="1" data-originate-json="1" data-dial-via-sip="1">
    @csrf
    <input type="hidden" name="fallback" value="sip">

    @if ($isCompactDial)
        @if ($hideExtension)
            <input type="hidden" name="from_extension" value="{{ $defaultExtension }}" data-dial-extension-sync>
        @else
            <div class="ghl-dialer-ext-inline">
                @include('communications.partials.dialer-extension-field', [
                    'routePrefix' => $routePrefix,
                    'morpheusExtensions' => $morpheusExtensions ?? [],
                    'phoneUsers' => $phoneUsers ?? [],
                    'defaultCallerId' => $defaultCallerId ?? null,
                    'callerSelectId' => $callerSelectId,
                    'formId' => $formId,
                ])
            </div>
        @endif

        <div class="ghl-dialer-active-screen hidden" data-dialer-active-screen aria-hidden="true">
            <div class="ghl-dialer-active-screen__hero">
                <p class="ghl-dialer-active-screen__number" data-dialer-active-peer></p>
                <p class="ghl-dialer-active-screen__status" data-dialer-active-status>Ringing</p>
                <p class="ghl-dialer-active-screen__timer hidden" data-dialer-active-timer>00:00</p>
            </div>
            <div class="ghl-dialer-active-screen__tools">
                <button type="button" class="ghl-dialer-active-screen__tool" data-dialer-active-notes-toggle
                    title="Call notes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                        <line x1="16" y1="13" x2="8" y2="13" />
                        <line x1="16" y1="17" x2="8" y2="17" />
                    </svg>
                    <span>Notes</span>
                </button>
                <button type="button" class="ghl-dialer-active-screen__tool" data-dialer-active-keypad-toggle
                    title="Show keypad" aria-expanded="false" aria-controls="ghl-dialer-active-keypad">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="4" y="3" width="16" height="18" rx="2" />
                        <circle cx="8" cy="8" r="1" fill="currentColor" />
                        <circle cx="12" cy="8" r="1" fill="currentColor" />
                        <circle cx="16" cy="8" r="1" fill="currentColor" />
                        <circle cx="8" cy="12" r="1" fill="currentColor" />
                        <circle cx="12" cy="12" r="1" fill="currentColor" />
                        <circle cx="16" cy="12" r="1" fill="currentColor" />
                        <circle cx="8" cy="16" r="1" fill="currentColor" />
                        <circle cx="12" cy="16" r="1" fill="currentColor" />
                        <circle cx="16" cy="16" r="1" fill="currentColor" />
                    </svg>
                    <span>Keypad</span>
                </button>
                <button type="button" class="ghl-dialer-active-screen__tool hidden" data-dialer-call-hold
                    title="Place caller on hold">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <rect x="6" y="4" width="4" height="16" rx="1" /><rect x="14" y="4" width="4" height="16" rx="1" />
                    </svg>
                    <span data-dialer-call-hold-label>Hold</span>
                </button>
                <button type="button" class="ghl-dialer-active-screen__tool hidden" data-dialer-call-mute
                    title="Mute your microphone" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"
                        data-dialer-call-mute-icon>
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" />
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                        <line x1="12" y1="19" x2="12" y2="23" />
                        <line x1="8" y1="23" x2="16" y2="23" />
                    </svg>
                    <span data-dialer-call-mute-label>Mute</span>
                </button>
                <button type="button" class="ghl-dialer-active-screen__tool hidden" data-dialer-call-transfer
                    title="Transfer this call">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="17 1 21 5 17 9" /><path d="M3 11V9a4 4 0 0 1 4-4h14" />
                        <polyline points="7 23 3 19 7 15" /><path d="M21 13v2a4 4 0 0 1-4 4H3" />
                    </svg>
                    <span>Transfer</span>
                </button>
                <button type="button" class="ghl-dialer-active-screen__tool hidden" data-dialer-call-record
                    title="Toggle call recording indicator">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" /><circle cx="12" cy="12" r="3" fill="currentColor" />
                    </svg>
                    <span data-dialer-call-record-label>Record</span>
                </button>
            </div>
            <div class="ghl-dialer-active-keypad hidden" id="ghl-dialer-active-keypad" data-dialer-active-keypad aria-hidden="true">
                <div class="ghl-dialer-active-keypad__display">
                    <p class="ghl-dialer-active-keypad__digits" data-dialer-active-keypad-digits aria-live="polite"></p>
                    <button type="button" class="ghl-dialer-active-keypad__delete" data-dialer-active-keypad-delete
                        aria-label="Delete last digit" title="Delete">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path>
                            <line x1="18" y1="9" x2="12" y2="15"></line>
                            <line x1="12" y1="9" x2="18" y2="15"></line>
                        </svg>
                        <span>Delete</span>
                    </button>
                </div>
                <div class="ghl-dialer-active-keypad__grid">
                    @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $tone)
                        <button type="button" class="ghl-dialer-active-keypad__key" data-dialer-active-dtmf="{{ $tone }}"
                            aria-label="Send {{ $tone }}">{{ $tone }}</button>
                    @endforeach
                </div>
                <div class="ghl-dialer-active-keypad__footer">
                    <button type="button" class="ghl-dialer-active-keypad__clear" data-dialer-active-keypad-clear
                        title="Clear all digits">Clear</button>
                    <button type="button" class="ghl-dialer-active-keypad__delete ghl-dialer-active-keypad__delete--text"
                        data-dialer-active-keypad-delete aria-label="Delete last digit" title="Delete">
                        Delete
                    </button>
                    <button type="button" class="ghl-dialer-active-keypad__hide" data-dialer-active-keypad-hide>Hide keypad</button>
                    <button type="button" class="ghl-dialer-active-keypad__amd" data-dialer-answering-machine
                        title="Mark as answering machine and end call">
                        Answering machine
                    </button>
                </div>
            </div>
            <div class="ghl-dialer-active-notes hidden" data-dialer-active-notes>
                <p class="ghl-dialer-active-notes__title">Call notes</p>
                <label class="ghl-dialer-active-notes__label" for="dialer-active-notes-field">Notes</label>
                <textarea id="dialer-active-notes-field" class="ghl-dialer-active-notes-input"
                    data-dialer-active-notes-input rows="3"
                    placeholder="Notes for this call…" maxlength="5000"></textarea>
                <label class="ghl-dialer-active-notes__label" for="dialer-active-comment-field">Comment</label>
                <textarea id="dialer-active-comment-field" class="ghl-dialer-active-notes-input"
                    data-dialer-active-comment-input rows="2"
                    placeholder="Comment for this call…" maxlength="5000"></textarea>
                <div class="ghl-dialer-active-notes-actions">
                    <span class="ghl-dialer-active-notes-status" data-dialer-active-notes-status aria-live="polite">Auto-saves to call log</span>
                    <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm" data-dialer-active-notes-save>Save</button>
                </div>
            </div>
            <div class="ghl-dialer-transfer-modal hidden" data-dialer-transfer-modal aria-hidden="true">
                <div class="ghl-dialer-transfer-modal__backdrop" data-dialer-transfer-close></div>
                <div class="ghl-dialer-transfer-modal__card" role="dialog" aria-modal="true" aria-labelledby="ghl-dialer-transfer-title">
                    <p class="ghl-dialer-transfer-modal__title" id="ghl-dialer-transfer-title">Transfer call</p>
                    <p class="ghl-dialer-transfer-modal__hint">Enter an extension or phone number</p>
                    <input type="tel" class="ghl-dialer-transfer-modal__input" data-dialer-transfer-input
                        placeholder="e.g. 1012 or +12025551234" inputmode="tel" autocomplete="off">
                    <div class="ghl-dialer-transfer-modal__actions">
                        <button type="button" class="ghl-dialer-transfer-modal__btn ghl-dialer-transfer-modal__btn--ghost"
                            data-dialer-transfer-close>Cancel</button>
                        <button type="button" class="ghl-dialer-transfer-modal__btn ghl-dialer-transfer-modal__btn--primary"
                            data-dialer-transfer-confirm>Transfer</button>
                    </div>
                </div>
            </div>
            <div class="ghl-dialer-active-screen__footer">
                <button type="button" class="ghl-dialer-active-screen__end" data-dialer-call-hangup
                    aria-label="End call" title="End call">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path
                            d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="ghl-dialer-phone-stage" data-dialer-phone-stage>
            <div class="ghl-dialer-phone-frame">
            <div class="ghl-dialer-number-wrap">
                <div class="ghl-dialer-input-shell" data-dialer-input-shell>
                    <input type="tel" id="{{ $numberInputId }}" name="destination"
                        class="ghl-dialer-display ghl-dialer-display--center ghl-dialer-field" placeholder="Enter the number"
                        value="{{ $dialValue }}" required autocomplete="tel" inputmode="tel" @disabled($extensions === [])>
                    <div class="ghl-dialer-call-layer hidden" data-dialer-call-layer data-state="idle" aria-live="polite"
                        aria-atomic="true">
                        <div class="ghl-dialer-call-layer__inner">
                            <span class="ghl-dialer-call-layer__badge" data-dialer-call-badge>Ringing</span>
                            <span class="ghl-dialer-call-layer__peer" data-dialer-call-peer></span>
                            <span class="ghl-dialer-call-layer__timer hidden" data-dialer-call-timer>00:00</span>
                        </div>
                    </div>
                </div>
                <p class="ghl-dialer-line-meta" data-dialer-line-meta aria-live="polite">
                    <span class="ghl-dialer-line-meta__badge" data-dialer-line-ext>Ext {{ $defaultExtension ?: '—' }}</span>
                    <span class="ghl-dialer-line-meta__sep" aria-hidden="true">·</span>
                    <span class="ghl-dialer-line-meta__did" data-dialer-from-did>{{ $resolvedDid }}</span>
                </p>
                <div class="ghl-dialer-call-actions hidden" data-dialer-call-actions>
                    <button type="button" class="ghl-webphone-btn-answer hidden" data-dialer-call-answer
                        title="Answer the incoming call on your browser line">Answer</button>
                    <button type="button" class="ghl-webphone-btn-hold hidden" data-dialer-call-hold
                        title="Place caller on hold">Hold</button>
                    <button type="button" class="ghl-webphone-btn-mute hidden" data-dialer-call-mute
                        title="Mute your microphone" aria-pressed="false">
                        <span data-dialer-call-mute-label>Mute</span>
                    </button>
                    <button type="button" class="ghl-webphone-btn-transfer hidden" data-dialer-call-transfer
                        title="Transfer this call">Transfer</button>
                    <button type="button" class="ghl-webphone-btn-record hidden" data-dialer-call-record
                        title="Toggle call recording indicator">
                        <span class="ghl-webphone-btn-record-dot" aria-hidden="true"></span>
                        <span data-dialer-call-record-label>Record</span>
                    </button>
                    <button type="button" class="ghl-webphone-btn-end-call hidden" data-dialer-call-hangup
                        title="End this call">End call</button>
                </div>
            </div>

            @php
                $iphoneKeyLetters = [
                    '1' => '',
                    '2' => 'ABC',
                    '3' => 'DEF',
                    '4' => 'GHI',
                    '5' => 'JKL',
                    '6' => 'MNO',
                    '7' => 'PQRS',
                    '8' => 'TUV',
                    '9' => 'WXYZ',
                    '*' => '',
                    '0' => '+',
                    '#' => '',
                ];
            @endphp
            <div class="ghl-dialer-keypad ghl-dialer-keypad--compact ghl-dialer-keypad--round ghl-dialer-keypad--with-actions ghl-dialer-keypad--iphone" id="{{ $keypadRootId }}">
                @foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '*', '0', '#'] as $key)
                    <button type="button" class="ghl-dialer-key ghl-dialer-key--round ghl-dialer-key--iphone" data-dial-key="{{ $key }}" aria-label="{{ $key }}">
                        <span class="ghl-dialer-key__digit">{{ $key }}</span>
                        @if (($iphoneKeyLetters[$key] ?? '') !== '')
                            <span class="ghl-dialer-key__letters">{{ $iphoneKeyLetters[$key] }}</span>
                        @else
                            <span class="ghl-dialer-key__letters ghl-dialer-key__letters--empty" aria-hidden="true">&nbsp;</span>
                        @endif
                    </button>
                @endforeach
                @if ($backspaceId)
                    <button type="button" id="{{ $backspaceId }}"
                        class="ghl-dialer-backspace-btn ghl-dialer-keypad__action ghl-dialer-keypad__action--delete is-hidden"
                        data-dial-backspace aria-label="Delete last digit" title="Delete">
                        <svg class="ghl-dialer-backspace-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 4H8l-7 8 7 8h13a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path>
                            <line x1="18" y1="9" x2="12" y2="15"></line>
                            <line x1="12" y1="9" x2="18" y2="15"></line>
                        </svg>
                    </button>
                @endif
                <button type="submit" id="{{ $dialBtnId }}"
                    class="ghl-dialer-call-icon-btn ghl-dialer-keypad__action ghl-dialer-keypad__action--call"
                    @disabled($extensions === []) aria-label="Place call" title="Call">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path
                            d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.01-.24 11.36 11.36 0 0 0 3.58.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.36 11.36 0 0 0 .57 3.58 1 1 0 0 1-.25 1.01l-2.2 2.2z" />
                    </svg>
                </button>
            </div>
            </div>
        </div>
    @else
        <div class="ghl-dialer-form-row">
            <div class="ghl-dialer-form-col">
                <label class="ch-label" for="{{ $callerSelectId }}">Extension</label>
                <select id="{{ $callerSelectId }}" name="from_extension" class="ch-input ghl-dialer-field" required
                    @disabled($extensions === [])>
                    <option value="" disabled @selected($defaultExtension === null || $defaultExtension === '')>Select extension
                    </option>
                    @foreach ($extensions as $ext)
                        @php $extNum = $ext['extension_num'] ?? ''; @endphp
                        @if (filled($extNum))
                            <option value="{{ $extNum }}" @selected((string) $defaultExtension === (string) $extNum)
                                data-outbound-did="{{ $ext['outbound_cid_num'] ?? $ext['caller_id_num'] ?? $defaultOutboundDid }}">
                                {{ $extNum }}
                                @if (!empty($ext['caller_id_num']))
                                    · {{ $ext['caller_id_num'] }}
                                @endif
                            </option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="ghl-dialer-form-col ghl-dialer-form-col--grow">
                <label class="ch-label" for="{{ $numberInputId }}">Number</label>
                <input type="tel" id="{{ $numberInputId }}" name="destination"
                    class="ch-input ghl-dialer-display ghl-dialer-field" placeholder="Enter the number"
                    value="{{ $dialValue }}" required autocomplete="tel" @disabled($extensions === [])>
            </div>
        </div>

        <p class="ghl-dialer-outbound-route" data-dialer-route-summary>
            CID <strong data-dialer-from-did>{{ $defaultOutboundDid ?: 'Not set' }}</strong>
        </p>

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
                @disabled($extensions === [])>Call with Morpheus CX</button>
        </div>
    @endif
</form>
