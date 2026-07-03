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
                <h3 class="ghl-inbox-rail-title mb-0">Phone</h3>
                <span class="ghl-webphone-status" data-webphone-status aria-live="polite">
                    <span class="ghl-webphone-dot" data-webphone-dot></span>
                    <span data-webphone-status-text>Offline</span>
                </span>
            </div>
            <p class="ghl-webphone-hint text-xs text-slate-500 mt-1 mb-2" data-webphone-hint>
                Built-in web phone — click Connect and allow microphone access.
            </p>
            <div class="ghl-webphone-call-info text-xs font-semibold text-slate-700 mb-2 hidden" data-webphone-call-info></div>
            <div class="ghl-webphone-actions flex gap-2 flex-wrap">
                <button type="button" class="comm-hub-btn comm-hub-btn-sm" data-webphone-connect>Connect</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-answer>Answer</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-hangup>Hang up</button>
                <button type="button" class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm hidden" data-webphone-bridge>Use embedded Morpheus phone</button>
            </div>
            <div class="ghl-webphone-bridge hidden mt-3" data-webphone-bridge-panel>
                <p class="text-xs text-slate-500 mb-2">Log in below with your Morpheus extension (<strong>1001</strong> / server password). Keep this open while dialing.</p>
                <iframe data-webphone-iframe title="Morpheus phone" class="ghl-webphone-iframe" src="about:blank" allow="microphone *; autoplay *"></iframe>
            </div>
            <audio id="apex-webphone-remote" autoplay playsinline data-webphone-remote></audio>
        </div>
    </section>
@endif
