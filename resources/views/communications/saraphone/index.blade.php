@extends('layouts.saraphone')

@section('title', 'SaraPhone')

@section('content')
    @php
        $iframeQuery = array_filter([
            'config_url' => $configUrl,
            'extension' => $extension,
            'embed' => '1',
            'debug' => '1',
            'dial' => $prefillDial ?? null,
        ]);
        $iframeSrc = url('/saraphone/saraphone.html').'?'.http_build_query($iframeQuery);
        $saraphoneBase = route($routePrefix . 'communications.morpheus.saraphone', ['extension' => $extension]);
    @endphp

    <div class="ghl-saraphone-page">
        <header class="ghl-saraphone-bar">
            <div class="ghl-saraphone-bar-main">
                <a href="{{ $dialerUrl }}" class="ghl-saraphone-back" aria-label="Back to dialer">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6" />
                    </svg>
                    <span>Dialer</span>
                </a>
                <div class="ghl-saraphone-bar-meta">
                    <strong>SaraPhone</strong>
                    <span>Ext {{ $extension }}</span>
                    <span class="ghl-saraphone-bar-dot" aria-hidden="true">·</span>
                    <span class="ghl-saraphone-bar-status" data-saraphone-status>Connecting…</span>
                </div>
            </div>
            <a href="{{ $iframeSrc }}" target="_blank" rel="noopener"
                class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Pop out</a>
        </header>

        <section class="ghl-saraphone-mic" id="saraphone-mic-banner" hidden>
            <div class="ghl-saraphone-mic-inner">
                <strong>Microphone access needed</strong>
                <p>Calls cannot connect until Chrome can use your microphone. Click below, then choose <strong>Allow</strong> when prompted.</p>
                <button type="button" class="comm-hub-btn ghl-saraphone-mic-btn" id="saraphone-mic-btn">Enable microphone</button>
                <p class="ghl-saraphone-mic-win">Windows: Settings → Privacy → Microphone → allow Chrome.</p>
            </div>
        </section>

        <div class="ghl-saraphone-main">
            <aside class="ghl-saraphone-side" aria-label="Dial controls">
                <section class="ghl-saraphone-callcard" aria-label="Place a call">
                    <form class="ghl-saraphone-quickdial" id="saraphone-quickdial-form" action="#" method="get">
                        <label class="ghl-saraphone-quickdial-label" for="saraphone-dial-input">Number to call</label>
                        <input type="tel" id="saraphone-dial-input" name="dial" value="{{ $prefillDial ?? '' }}"
                            class="ghl-saraphone-quickdial-input" placeholder="e.g. 2722001232"
                            inputmode="tel" autocomplete="tel" aria-label="Phone number to dial">
                        <button type="button" class="comm-hub-btn ghl-saraphone-call-btn" id="saraphone-call-btn">
                            Call
                        </button>
                    </form>
                    <p class="ghl-saraphone-call-hint" data-saraphone-call-hint>
                        Click <strong>Enable microphone</strong> if shown. Wait for <strong>Registered</strong>, then press <strong>Call</strong>.
                    </p>
                </section>

                <details class="ghl-saraphone-tips ghl-saraphone-tips--side">
                    <summary>Help & connection tips</summary>
                    <ol class="ghl-saraphone-steps ghl-saraphone-steps--compact">
                        <li><span>1</span> Phone logs in as ext {{ $extension }} automatically</li>
                        <li><span>2</span> Enter number (10 digits or +1…) and press Call</li>
                        <li><span>3</span> Allow microphone when prompted</li>
                    </ol>
                    <p>
                        WSS <code>{{ $wssUrl ?? ('wss://' . $wssHost . ':' . $wssPort . '/') }}</code> · SIP <code>{{ $sipDomain }}</code>.
                        Only one softphone per extension — close Zoiper or the built-in webphone first.
                    </p>
                </details>
            </aside>

            <div class="ghl-saraphone-phone">
                <iframe
                    class="ghl-saraphone-iframe"
                    id="saraphone-frame"
                    title="SaraPhone WebRTC"
                    data-base-src="{{ url('/saraphone/saraphone.html') }}"
                    data-config-url="{{ $configUrl }}"
                    data-extension="{{ $extension }}"
                    src="{{ $iframeSrc }}"
                    allow="microphone *; autoplay *; clipboard-read *; clipboard-write *"
                    referrerpolicy="same-origin"></iframe>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            var frame = document.getElementById('saraphone-frame');
            var dialInput = document.getElementById('saraphone-dial-input');
            var callBtn = document.getElementById('saraphone-call-btn');
            var statusEl = document.querySelector('[data-saraphone-status]');
            var hintEl = document.querySelector('[data-saraphone-call-hint]');
            var micBanner = document.getElementById('saraphone-mic-banner');
            var micBtn = document.getElementById('saraphone-mic-btn');
            var registered = false;
            var micGranted = false;

            function showMicBanner(show) {
                if (micBanner) {
                    micBanner.hidden = !show;
                }
            }

            function requestMicInFrame() {
                return new Promise(function (resolve) {
                    var done = false;
                    function onMsg(event) {
                        if (event.origin !== window.location.origin) return;
                        if (!event.data || event.data.type !== 'apex-mic-status') return;
                        done = true;
                        window.removeEventListener('message', onMsg);
                        micGranted = Boolean(event.data.granted);
                        showMicBanner(!micGranted);
                        resolve(micGranted);
                    }
                    window.addEventListener('message', onMsg);
                    postToFrame({ type: 'apex-request-mic' });
                    window.setTimeout(function () {
                        if (!done) {
                            window.removeEventListener('message', onMsg);
                            resolve(micGranted);
                        }
                    }, 10000);
                });
            }

            if (micBtn) {
                micBtn.addEventListener('click', function () {
                    frame.focus();
                    requestMicInFrame().then(function (ok) {
                        if (ok) {
                            setHint('Microphone ready — enter a number and press Call.');
                        } else {
                            setHint('Microphone still blocked. Allow mic in Chrome and Windows Privacy settings.');
                        }
                    });
                });
            }

            showMicBanner(true);

            function iframeSrc(dial) {
                var params = new URLSearchParams({
                    config_url: frame.dataset.configUrl || '',
                    extension: frame.dataset.extension || '',
                    embed: '1',
                    debug: '1',
                });
                if (dial) {
                    params.set('dial', dial);
                }
                return (frame.dataset.baseSrc || '') + '?' + params.toString();
            }

            function postToFrame(message) {
                if (!frame || !frame.contentWindow) return;
                frame.contentWindow.postMessage(message, window.location.origin);
            }

            function setHint(text) {
                if (hintEl) {
                    hintEl.textContent = text;
                }
            }

            function triggerCall(dial) {
                var number = String(dial || '').trim();
                if (!number) {
                    dialInput.focus();
                    return;
                }
                if (!registered) {
                    setHint('Still connecting — wait for Registered, then try again.');
                    return;
                }
                requestMicInFrame().then(function (ok) {
                    if (!ok) {
                        setHint('Allow microphone access first (click Enable microphone above).');
                        showMicBanner(true);
                        return;
                    }
                    setHint('Placing call to ' + number + '…');
                    postToFrame({ type: 'apex-saraphone-dial', dial: number });
                });
            }

            if (callBtn && dialInput) {
                callBtn.addEventListener('click', function () {
                    triggerCall(dialInput.value);
                });
                dialInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        triggerCall(dialInput.value);
                    }
                });
            }

            window.addEventListener('message', function (event) {
                if (event.origin !== window.location.origin || !event.data) return;

                if (event.data.type === 'apex-saraphone-debug' && event.data.payload) {
                    var p = event.data.payload;
                    var line = '[Apex SaraPhone Hub] ' + (p.event || 'event');
                    if (event.data.level === 'error') {
                        console.error(line, p.detail || {});
                    } else if (event.data.level === 'warn') {
                        console.warn(line, p.detail || {});
                    } else {
                        console.info(line, p.detail || {});
                    }
                }

                if (event.data.type === 'apex-saraphone-status') {
                    registered = Boolean(event.data.registered);
                    if (statusEl) {
                        statusEl.textContent = registered ? 'Registered' : (event.data.message || 'Offline');
                        statusEl.classList.toggle('is-live', registered);
                    }
                    if (registered && hintEl && !event.data.callPhase) {
                        setHint('Click Enable microphone if needed, then enter a number and press Call.');
                    }
                }

                if (event.data.type === 'apex-mic-status') {
                    micGranted = Boolean(event.data.granted);
                    showMicBanner(!micGranted);
                    if (micGranted) {
                        setHint('Microphone ready — enter a number and press Call.');
                    }
                }

                if (event.data.type === 'apex-saraphone-call') {
                    var phase = event.data.phase || '';
                    var dest = event.data.destination || '';
                    if (statusEl) {
                        var labels = {
                            preparing: 'Preparing…',
                            dialing: 'Dialing…',
                            ringing: 'Ringing destination…',
                            connected: 'Connected',
                            failed: 'Call failed',
                            ended: 'Call ended',
                        };
                        statusEl.textContent = labels[phase] || phase;
                        statusEl.classList.toggle('is-live', phase === 'connected' || phase === 'ringing');
                    }
                    if (event.data.message) {
                        setHint(event.data.message);
                    } else if (dest && phase === 'connected') {
                        setHint('Connected to ' + dest + '.');
                    }
                }
            });
        })();
    </script>
@endpush
