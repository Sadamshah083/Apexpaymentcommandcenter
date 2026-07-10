@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Phone')

@section('content')
    @php
        $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
    @endphp

    <div class="ghl-inbox ch-hub-shell ghl-comm-app ghl-comm-phone-mode">
        @if (!empty($warnings) || !empty($error))
            <div class="ghl-inbox-alerts">
                @if ($error && str_contains($error, 'not configured'))
                    <div class="ghl-setup-alert">
                        <p class="ghl-setup-alert-title">Morpheus CX is not configured</p>
                        <p class="ghl-setup-alert-desc">Add your Call-Control API key to <code>.env</code>, then clear
                            config cache. The phone dialer will work once Morpheus is connected.</p>
                        <ul class="ghl-setup-alert-list">
                            <li><code>MORPHEUS_HOST=apexone.morpheus.cx</code></li>
                            <li><code>MORPHEUS_API_KEY=ck_your_key_here</code></li>
                            <li><code>MORPHEUS_SIP_HOST=apexone.morpheus.cx</code> (for softphone dial fallback)</li>
                        </ul>
                    </div>
                @else
                    @foreach ($warnings ?? [] as $warning)
                        <div class="comm-hub-alert comm-hub-alert-warning ghl-inbox-alert-dismissible"
                            data-dismiss-key="comm-hub-warning-{{ md5($warning) }}">
                            <span class="ghl-inbox-alert-text">{{ $warning }}</span>
                            <button type="button" class="ghl-inbox-alert-close" data-dismiss-target
                                aria-label="Dismiss">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
                                </svg>
                            </button>
                        </div>
                    @endforeach
                    @if ($error)
                        <div class="comm-hub-alert comm-hub-alert-warning ghl-inbox-alert-dismissible"
                            data-dismiss-key="comm-hub-error-{{ md5($error) }}">
                            <span class="ghl-inbox-alert-text">{{ $error }}</span>
                            <button type="button" class="ghl-inbox-alert-close" data-dismiss-target
                                aria-label="Dismiss">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M6 18L18 6" />
                                </svg>
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        @endif

        <div class="ghl-inbox-card ghl-comm-card">
            @include('communications.inbox.partials.toolbar-dialer')

            <div class="ghl-inbox-body ghl-inbox-body-wide">
                <section class="ghl-inbox-conversation">
                    @include('communications.inbox.partials.panels.dialer')
                </section>
            </div>
        </div>
    </div>

    @include('communications.partials.audio-player')
@endsection

@push('scripts')
    @include('communications.partials.audio-player-script')
    <script>
        (function () {
            function enterCommMode() {
                document.body.classList.add('ghl-comm-mode');
                document.body.classList.remove('app-sidebar-collapsed');
            }

            function leaveCommMode() {
                document.body.classList.remove('ghl-comm-mode');
            }

            enterCommMode();
            document.addEventListener('turbo:load', function () {
                if (document.querySelector('.ghl-comm-app')) {
                    enterCommMode();
                }
            });
            document.addEventListener('turbo:before-render', function (e) {
                if (!e.detail.newBody.querySelector('.ghl-comm-app')) {
                    leaveCommMode();
                }
            });

            function initAlertDismiss(root) {
                var scope = root || document;
                var storage = null;
                try {
                    storage = window.sessionStorage;
                } catch (e) {
                    storage = null;
                }

                scope.querySelectorAll('.ghl-inbox-alert-dismissible').forEach(function (alert) {
                    var key = alert.getAttribute('data-dismiss-key');
                    if (key && storage && storage.getItem(key) === '1') {
                        alert.setAttribute('hidden', 'hidden');
                        return;
                    }
                    var btn = alert.querySelector('[data-dismiss-target]');
                    if (!btn || btn.dataset.bound === '1') return;
                    btn.dataset.bound = '1';
                    btn.addEventListener('click', function () {
                        alert.setAttribute('hidden', 'hidden');
                        if (key && storage) {
                            try { storage.setItem(key, '1'); } catch (e) { /* noop */ }
                        }
                    });
                });
            }

            initAlertDismiss(document);
            document.addEventListener('turbo:load', function () { initAlertDismiss(document); });
            document.addEventListener('turbo:frame-load', function (e) { initAlertDismiss(e.target); });
        })();
    </script>
@endpush
