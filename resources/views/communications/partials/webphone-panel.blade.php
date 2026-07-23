@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $webrtcEnabled = (bool) config('integrations.morpheus.webrtc_enabled', true);
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
    $defaultOutboundDid = config('integrations.communications.default_outbound_did');
    $portalUrl = app(\App\Services\Communications\ZoomClickToCallService::class)->portalUrl();
    $wssUrl = app(\App\Services\Communications\ZoomClickToCallService::class)->resolveSipWssUrl();
    $layout = $layout ?? 'sidebar';
    $isCenter = $layout === 'center';
    $isRightRail = $layout === 'right-rail';
    $isMinimal = $layout === 'minimal';
    $isCompactPhone = $isCenter || $isRightRail || $isMinimal;
    $callHistoryUrl = $callHistoryUrl ?? route($routePrefix . 'communications.index');
@endphp

@if ($webrtcEnabled && app(\App\Services\Integrations\ZoomApiService::class)->isConfigured())
    <section class="ghl-tools-section ghl-webphone-section {{ $isCenter ? 'ghl-webphone-section--center' : '' }}" id="apex-webphone-section"
        data-webphone-panel
        data-config-url="{{ route($routePrefix . 'communications.morpheus.webphone.config') }}"
        data-prepare-url="{{ route($routePrefix . 'communications.morpheus.webphone.prepare') }}"
        data-hangup-url="{{ route($routePrefix . 'communications.morpheus.calls.hangup', ['uuid' => '__UUID__']) }}"
        data-release-extension-url="{{ route($routePrefix . 'communications.morpheus.calls.release-extension') }}"
        data-record-url="{{ route($routePrefix . 'communications.morpheus.calls.record', ['uuid' => '__UUID__']) }}"
        data-hold-url="{{ route($routePrefix . 'communications.morpheus.calls.hold', ['uuid' => '__UUID__']) }}"
        data-unhold-url="{{ route($routePrefix . 'communications.morpheus.calls.unhold', ['uuid' => '__UUID__']) }}"
        data-transfer-url="{{ route($routePrefix . 'communications.morpheus.calls.transfer', ['uuid' => '__UUID__']) }}"
        data-call-status-url="{{ route($routePrefix . 'communications.morpheus.calls.status', ['uuid' => '__UUID__']) }}"
        data-call-events-url="{{ route($routePrefix . 'communications.morpheus.calls.events', ['uuid' => '__UUID__']) }}"
        data-call-events-ws-url="{{ (request()->secure() ? 'wss' : 'ws') }}://{{ request()->getHost() }}{{ config('integrations.morpheus.call_events_ws_public_path', '/communications-ws/ws') }}?uuid=__UUID__"
        data-destination-connected-url="{{ route($routePrefix . 'communications.morpheus.calls.destination-connected', ['uuid' => '__UUID__']) }}"
        data-call-ended-url="{{ route($routePrefix . 'communications.morpheus.calls.ended', ['uuid' => '__UUID__']) }}"
        data-originate-url="{{ route($routePrefix . 'communications.morpheus.calls.originate') }}"
        data-portal-url="{{ $portalUrl !== '#' ? $portalUrl . 'agent/' : 'https://' . config('integrations.morpheus.host') . '/agent/' }}"
        data-default-extension="{{ $defaultExtension }}"
        data-default-outbound-did="{{ $defaultOutboundDid }}"
        data-wss-url="{{ $wssUrl }}"
        data-csrf="{{ csrf_token() }}">
        <div class="ghl-webphone-panel comm-hub-card {{ $isCompactPhone ? 'ghl-webphone-panel--center ghl-webphone-panel--enterprise' : '' }} {{ $isRightRail ? 'ghl-webphone-panel--right-rail' : '' }} {{ $isMinimal ? 'ghl-webphone-panel--minimal' : '' }}">
            @if ($isMinimal)
                <div class="ghl-comm-connect-group" data-comm-connect-group>
                    <button type="button" class="ghl-comm-connect-group__main" data-webphone-connect>
                        <span class="ghl-comm-connect-group__label" data-webphone-connect-label>Connect</span>
                        <span class="ghl-comm-connect-group__phone" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                            </svg>
                        </span>
                    </button>
                    <button type="button" class="ghl-comm-connect-group__delete hidden" data-webphone-disconnect
                        aria-label="Disconnect line" title="Disconnect">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <polyline points="3 6 5 6 21 6" />
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                            <path d="M10 11v6M14 11v6" />
                            <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                        </svg>
                    </button>
                    <div class="sr-only" aria-hidden="true">
                        <button type="button" data-webphone-answer>Answer call</button>
                        <button type="button" data-webphone-hangup>End call</button>
                        <button type="button" data-webphone-bridge>Open fallback phone</button>
                    </div>
                </div>
            @endif

            @unless ($isCompactPhone)
            <div class="ghl-webphone-header">
                <div>
                    <h3 class="ghl-inbox-rail-title mb-0">Phone</h3>
                    <p class="ghl-webphone-subtitle">Connect once, then place and receive calls in real time.</p>
                </div>
                <span class="ghl-webphone-status" data-webphone-status aria-live="polite">
                    <span class="ghl-webphone-dot" data-webphone-dot></span>
                    <span data-webphone-status-text>Offline</span>
                </span>
            </div>
            @endunless

            @unless ($isCompactPhone)
            <x-communications.molecules.stat-grid
                class="ghl-webphone-summary"
                :items="[
                    ['label' => 'Extension', 'value' => $defaultExtension ?: '—'],
                    ['label' => 'SIP realm', 'value' => config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host')],
                ]"
            />
            <p class="text-xs text-slate-500 mb-1 mt-1" data-webphone-transport-wrap>
                WebSocket: <strong data-webphone-transport>{{ $wssUrl }}</strong>
            </p>
            <p class="text-xs text-slate-500 mb-3" data-webphone-wss-status aria-live="polite">
                WSS status: <strong data-webphone-wss-status-text>Not connected</strong>
                <span class="text-slate-400">· DevTools → Network → WS filter</span>
            </p>
            @else
            <p class="sr-only" data-webphone-transport-wrap>
                Transport: <strong data-webphone-transport>{{ config('integrations.morpheus.sip_wss_url') ?: 'Auto WSS' }}</strong>
            </p>
            @endunless

            <div class="ghl-webphone-stage-row {{ $isCompactPhone && !$isMinimal ? 'ghl-webphone-stage-row--compact' : '' }} {{ $isMinimal ? 'sr-only' : '' }}">
                <span class="ghl-webphone-stage" data-webphone-stage data-state="offline">Idle</span>
                @unless ($isCompactPhone)
                    <span class="ghl-webphone-stage-note" data-webphone-stage-note>Waiting to connect your line.</span>
                @else
                    <span class="sr-only" data-webphone-stage-note>Waiting to connect your line.</span>
                @endunless
            </div>

            @unless ($isCompactPhone)
            <p class="ghl-webphone-hint text-xs text-slate-500 mt-1 mb-0" data-webphone-hint>
                Click <strong>Connect line</strong> (opens WebSocket to Morpheus), then press <strong>Call</strong> in the dialer.
                Calls use the originate API and auto-answer on your browser line.
            </p>
            @else
            <p class="sr-only" data-webphone-hint>Click Connect line and allow microphone access when prompted.</p>
            @endunless

            <div class="ghl-webphone-call-card hidden" data-webphone-call-card {{ $isMinimal ? 'hidden' : '' }}>
                <div class="ghl-webphone-call-card-head">
                    <div>
                        <p class="ghl-webphone-call-title" data-webphone-call-title>Incoming call</p>
                        <p class="ghl-webphone-call-subtitle" data-webphone-call-subtitle>Waiting for call activity…</p>
                    </div>
                    <div class="ghl-webphone-call-timer" data-webphone-call-timer>00:00</div>
                </div>
                <div class="ghl-webphone-call-info text-xs font-semibold text-slate-700 hidden" data-webphone-call-info></div>
                <div class="ghl-webphone-call-controls hidden" data-webphone-call-controls>
                    <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-hold title="Place caller on hold">
                        Hold
                    </button>
                    <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-mute title="Mute your microphone" aria-pressed="false">
                        Mute
                    </button>
                    <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-transfer title="Transfer this call">
                        Transfer
                    </button>
                    <button type="button" class="ghl-webphone-btn-record" data-webphone-record title="Toggle call recording indicator">
                        <span class="ghl-webphone-btn-record-dot" aria-hidden="true"></span>
                        <span data-webphone-record-label>Record</span>
                    </button>
                    <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm ghl-webphone-btn-end-call" data-webphone-end-call title="End this call">
                        End call
                    </button>
                </div>
            </div>

            @unless ($isMinimal)
            <div class="ghl-webphone-actions">
                <button type="button" class="ch-btn ch-btn--primary {{ $isCompactPhone ? 'ch-btn--sm' : 'ch-btn--sm' }}" data-webphone-connect>Connect line</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-disconnect>Disconnect</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-answer
                    title="Answer the incoming call on your browser line">Answer call</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-hangup
                    title="Decline or end the current call">End call</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-bridge>Open fallback phone</button>
            </div>
            @endunless

            @unless ($isCompactPhone)
            <ul class="ghl-webphone-control-help text-xs text-slate-500 mt-2 mb-0 list-disc pl-4 space-y-1">
                <li><strong>Connect line</strong> — WebSocket to Morpheus (<code>wss://…:7443</code>) registers your browser phone.</li>
                <li><strong>Call</strong> in the dialer — POST to originate API; Morpheus rings your line, then dials the destination.</li>
                <li><strong>Caller ID</strong> — shows as <strong>ApexOne Payments</strong> with your extension DID (reduces spam flags).</li>
            </ul>

            <div class="ghl-webphone-helper">
                <span class="ghl-webphone-helper-dot"></span>
                If browser audio acts up, you can open the Morpheus fallback phone below and keep dialing from Apex.
            </div>
            @endunless

            <div class="ghl-webphone-bridge hidden mt-3" data-webphone-bridge-panel>
                <p class="text-xs text-slate-500 mb-2">
                    Log in below with your Morpheus extension
                    (<strong data-webphone-bridge-extension>{{ $defaultExtension ?: config('integrations.communications.default_caller_id', '1020') }}</strong> / server password).
                    Keep it open while dialing if you need the fallback phone.
                </p>
                <iframe data-webphone-iframe title="Morpheus phone" class="ghl-webphone-iframe" src="about:blank" allow="microphone *; autoplay *"></iframe>
            </div>
            <span class="sr-only" data-webphone-extension>{{ $defaultExtension ?: '—' }}</span>
            <span class="sr-only" data-webphone-domain>{{ config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host') }}</span>
            <span class="sr-only" data-webphone-status aria-live="polite">
                <span class="ch-status-dot" data-webphone-dot></span>
                <span data-webphone-status-text>Offline</span>
            </span>
            <audio id="apex-webphone-remote" autoplay playsinline webkit-playsinline
                data-webphone-remote preload="none"></audio>
        </div>
    </section>
@endif
