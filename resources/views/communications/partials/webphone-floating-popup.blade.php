@if ((bool) config('integrations.morpheus.webrtc_enabled', true) && app(\App\Services\Integrations\ZoomApiService::class)->isConfigured())
    <div class="ghl-webphone-floating hidden" data-webphone-floating data-state="offline" aria-live="assertive"
        aria-atomic="true" role="dialog" aria-labelledby="ghl-call-popup-title">
        <div class="ghl-call-popup-visual hidden" data-webphone-floating-visual aria-hidden="true">
            <span class="ghl-call-popup-ring"></span>
            <span class="ghl-call-popup-ring ghl-call-popup-ring--delay"></span>
            <span class="ghl-call-popup-phone-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path
                        d="M6.6 10.8a15.9 15.9 0 006.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.3 1.2.4 2.5.6 3.8.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.3 21 3 13.7 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.6.6 3.8.1.4 0 .8-.3 1.1L6.6 10.8z"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </div>

        <div class="ghl-call-popup-badge hidden" data-webphone-floating-badge>Ringing</div>

        <div class="ghl-webphone-floating-head">
            <div class="min-w-0">
                <p class="ghl-webphone-floating-title" id="ghl-call-popup-title" data-webphone-floating-title>Outgoing call</p>
                <p class="ghl-webphone-floating-subtitle" data-webphone-floating-subtitle>Ringing destination…</p>
            </div>
        </div>

        <p class="ghl-webphone-floating-detail hidden" data-webphone-floating-detail></p>

        <div class="ghl-call-popup-timer-row hidden" data-webphone-floating-timer-row>
            <span class="ghl-call-popup-timer-label" data-webphone-floating-timer-label>Connected time</span>
            <span class="ghl-call-popup-timer-value" data-webphone-floating-timer>00:00</span>
        </div>

        <div class="ghl-webphone-floating-actions">
            <button type="button" class="ghl-webphone-btn-answer hidden" data-webphone-floating-answer
                title="Answer the incoming call on your browser line">Answer</button>
            <button type="button" class="ghl-webphone-btn-record hidden" data-webphone-floating-record
                title="Toggle call recording indicator">
                <span class="ghl-webphone-btn-record-dot" aria-hidden="true"></span>
                <span data-webphone-floating-record-label>Record</span>
            </button>
            <button type="button" class="ghl-webphone-btn-end-call hidden" data-webphone-floating-hangup
                title="End this call">End call</button>
        </div>
    </div>
@endif
