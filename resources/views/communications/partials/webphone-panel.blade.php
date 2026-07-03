@php
    $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    $webrtcEnabled = (bool) config('integrations.morpheus.webrtc_enabled', true);
    $defaultExtension = $defaultCallerId ?? config('integrations.communications.default_caller_id');
    $portalUrl = app(\App\Services\Communications\ZoomClickToCallService::class)->portalUrl();
@endphp

@if ($webrtcEnabled && app(\App\Services\Integrations\ZoomApiService::class)->isConfigured())
    <section class="ghl-tools-section ghl-webphone-section" id="apex-webphone-section"
        data-webphone-panel
        data-config-url="{{ route($routePrefix . 'communications.morpheus.webphone.config') }}"
        data-prepare-url="{{ route($routePrefix . 'communications.morpheus.webphone.prepare') }}"
        data-portal-url="{{ $portalUrl !== '#' ? $portalUrl . 'agent/' : 'https://' . config('integrations.morpheus.host') . '/agent/' }}"
        data-default-extension="{{ $defaultExtension }}"
        data-csrf="{{ csrf_token() }}">
        <div class="ghl-webphone-panel comm-hub-card">
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

            <div class="ghl-webphone-summary">
                <div class="ghl-webphone-summary-item">
                    <span class="ghl-webphone-summary-label">Your line</span>
                    <strong class="ghl-webphone-summary-value" data-webphone-extension>{{ $defaultExtension ?: '—' }}</strong>
                </div>
                <div class="ghl-webphone-summary-item">
                    <span class="ghl-webphone-summary-label">SIP realm</span>
                    <strong class="ghl-webphone-summary-value" data-webphone-domain>{{ config('integrations.morpheus.sip_host') ?: config('integrations.morpheus.host') }}</strong>
                </div>
                <div class="ghl-webphone-summary-item">
                    <span class="ghl-webphone-summary-label">Transport</span>
                    <strong class="ghl-webphone-summary-value" data-webphone-transport>{{ config('integrations.morpheus.sip_wss_url') ?: 'Auto WSS' }}</strong>
                </div>
            </div>

            <div class="ghl-webphone-stage-row">
                <span class="ghl-webphone-stage" data-webphone-stage data-state="offline">Idle</span>
                <span class="ghl-webphone-stage-note" data-webphone-stage-note>Waiting to connect your line.</span>
            </div>

            <p class="ghl-webphone-hint text-xs text-slate-500 mt-1 mb-0" data-webphone-hint>
                Built-in web phone — click Connect and allow microphone access.
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
            </div>

            <div class="ghl-webphone-actions">
                <button type="button" class="comm-hub-btn comm-hub-btn-sm" data-webphone-connect>Connect line</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-disconnect>Disconnect</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-answer>Answer</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-hangup>Hang up</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-bridge>Open fallback phone</button>
            </div>

            <div class="ghl-webphone-helper">
                <span class="ghl-webphone-helper-dot"></span>
                If browser audio acts up, you can open the Morpheus fallback phone below and keep dialing from Apex.
            </div>

            <div class="ghl-webphone-bridge hidden mt-3" data-webphone-bridge-panel>
                <p class="text-xs text-slate-500 mb-2">
                    Log in below with your Morpheus extension
                    (<strong data-webphone-bridge-extension>{{ $defaultExtension ?: '1001' }}</strong> / server password).
                    Keep it open while dialing if you need the fallback phone.
                </p>
                <iframe data-webphone-iframe title="Morpheus phone" class="ghl-webphone-iframe" src="about:blank" allow="microphone *; autoplay *"></iframe>
            </div>
            <audio id="apex-webphone-remote" autoplay playsinline data-webphone-remote></audio>
        </div>
    </section>
@endif
