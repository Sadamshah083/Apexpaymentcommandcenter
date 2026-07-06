@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $webrtcEnabled = (bool) config('integrations.morpheus.webrtc_enabled', true);
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
    $defaultOutboundDid = config('integrations.communications.default_outbound_did');
    $portalUrl = app(\App\Services\Communications\ZoomClickToCallService::class)->portalUrl();
    $layout = $layout ?? 'sidebar';
    $isCenter = $layout === 'center';
@endphp

@if ($webrtcEnabled && app(\App\Services\Integrations\ZoomApiService::class)->isConfigured())
    <section class="ghl-tools-section ghl-webphone-section {{ $isCenter ? 'ghl-webphone-section--center' : '' }}" id="apex-webphone-section"
        data-webphone-panel
        data-config-url="{{ route($routePrefix . 'communications.morpheus.webphone.config') }}"
        data-prepare-url="{{ route($routePrefix . 'communications.morpheus.webphone.prepare') }}"
        data-hangup-url="{{ route($routePrefix . 'communications.morpheus.calls.hangup', ['uuid' => '__UUID__']) }}"
        data-hold-url="{{ route($routePrefix . 'communications.morpheus.calls.hold', ['uuid' => '__UUID__']) }}"
        data-unhold-url="{{ route($routePrefix . 'communications.morpheus.calls.unhold', ['uuid' => '__UUID__']) }}"
        data-transfer-url="{{ route($routePrefix . 'communications.morpheus.calls.transfer', ['uuid' => '__UUID__']) }}"
        data-call-status-url="{{ route($routePrefix . 'communications.morpheus.calls.status', ['uuid' => '__UUID__']) }}"
        data-portal-url="{{ $portalUrl !== '#' ? $portalUrl . 'agent/' : 'https://' . config('integrations.morpheus.host') . '/agent/' }}"
        data-default-extension="{{ $defaultExtension }}"
        data-default-outbound-did="{{ $defaultOutboundDid }}"
        data-csrf="{{ csrf_token() }}">
        <div class="ghl-webphone-panel comm-hub-card {{ $isCenter ? 'ghl-webphone-panel--center ghl-webphone-panel--enterprise' : '' }}">
            @unless ($isCenter)
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

            <x-communications.molecules.stat-grid
                class="ghl-webphone-summary"
                :items="[
                    ['label' => 'Extension', 'value' => $defaultExtension ?: '—'],
                    ['label' => 'SIP realm', 'value' => config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host')],
                ]"
            />
            <p class="text-xs text-slate-500 mb-3 mt-1" data-webphone-transport-wrap>
                Transport: <strong data-webphone-transport>{{ config('integrations.morpheus.sip_wss_url') ?: 'Auto WSS' }}</strong>
            </p>

            <div class="ghl-webphone-stage-row">
                <span class="ghl-webphone-stage" data-webphone-stage data-state="offline">Idle</span>
                <span class="ghl-webphone-stage-note" data-webphone-stage-note>Waiting to connect your line.</span>
            </div>

            <p class="ghl-webphone-hint text-xs text-slate-500 mt-1 mb-0" data-webphone-hint>
                Click <strong>Connect line</strong> and allow microphone access when prompted.
            </p>

            <div class="ghl-webphone-call-card hidden" data-webphone-call-card>
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

            <div class="ghl-webphone-actions">
                <button type="button" class="ch-btn ch-btn--primary {{ $isCenter ? '' : 'ch-btn--sm' }}" data-webphone-connect>Connect line</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-disconnect>Disconnect</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-answer
                    title="Answer the incoming call on your browser line">Answer call</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-hangup
                    title="Decline or end the current call">End call</button>
                <button type="button" class="ch-btn ch-btn--secondary ch-btn--sm hidden" data-webphone-bridge>Open fallback phone</button>
            </div>

            @unless ($isCenter)
            <ul class="ghl-webphone-control-help text-xs text-slate-500 mt-2 mb-0 list-disc pl-4 space-y-1">
                <li><strong>Connect line</strong> — registers your browser phone with Morpheus (do this once per session).</li>
                <li><strong>Answer call</strong> — picks up when Morpheus rings your line for outbound or inbound calls.</li>
                <li><strong>Hang up</strong> — declines a ringing call or ends an active call.</li>
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
            <audio id="apex-webphone-remote" autoplay playsinline data-webphone-remote></audio>
        </div>
    </section>
@endif
