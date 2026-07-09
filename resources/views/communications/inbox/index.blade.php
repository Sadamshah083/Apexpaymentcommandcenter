@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications')

@section('content')
    @php
        $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
        // Full-width dialer only when Phone panel is explicitly opened.
        $isDialerView = ($panel ?? '') === 'dialer';
        $baseQuery = array_filter([
            'channel' => $channel ?? 'inbox',
            'search' => $filters['search'] ?? null,
            'filter' => $filters['filter'] ?? null,
            'direction' => $filters['direction'] ?? null,
            'status' => $filters['status'] ?? null,
            'from' => $isDialerView ? null : ($filters['from'] ?? null),
            'to' => $isDialerView ? null : ($filters['to'] ?? null),
            'list_page' => request('list_page'),
            'panel_page' => request('panel_page'),
            'page_token' => request('page_token'),
            'msg_page_token' => request('msg_page_token'),
            'contact' => request('contact'),
            'session' => request('session'),
            'call' => request('call'),
            'voicemail' => request('voicemail'),
            'recording' => request('recording'),
            'chat_owner' => request('chat_owner'),
            'chat_channel' => request('chat_channel'),
            'chat_contact' => request('chat_contact'),
        ]);
        $isWidePanel = ($panel ?? '') === 'settings' || $isDialerView;
        $channelLabel = $channels[$channel]['label'] ?? 'Inbox';
    @endphp

    <div class="ghl-inbox ch-hub-shell ghl-comm-app {{ $isDialerView ? 'ghl-comm-phone-mode' : '' }}">
        @if (!empty($warnings) || !empty($error))
            <div class="ghl-inbox-alerts">
                @if ($error && str_contains($error, 'not configured'))
                    <div class="ghl-setup-alert">
                        <p class="ghl-setup-alert-title">Morpheus CX is not configured</p>
                        <p class="ghl-setup-alert-desc">Add your Call-Control API key to <code>.env</code>, then clear
                            config cache. The hub will show live calls, SMS, and dialer once connected.</p>
                        <ul class="ghl-setup-alert-list">
                            <li><code>MORPHEUS_HOST=apexone.morpheus.cx</code></li>
                            <li><code>MORPHEUS_API_KEY=ck_your_key_here</code></li>
                            <li><code>MORPHEUS_SIP_HOST=apexone.morpheus.cx</code> (for softphone dial fallback)</li>
                        </ul>
                        @if ($hubAccess['canConfigure'] ?? false)
                            <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'settings'])) }}"
                                class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm ghl-setup-alert-link">Open
                                integration settings</a>
                        @endif
                    </div>
                @else
                    @foreach ($warnings ?? [] as $index => $warning)
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
            @include('communications.inbox.partials.toolbar')

            {{-- Channel tabs removed; use toolbar Messages / Phone / channels menu --}}

            @unless ($isWidePanel)
                <nav class="ch-mobile-panels" aria-label="Switch panel" data-ch-mobile-panels>
                    <button type="button" class="ch-mobile-panels__btn" data-ch-panel-btn="list"
                        aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <line x1="8" y1="6" x2="21" y2="6" /><line x1="8" y1="12" x2="21" y2="12" /><line x1="8" y1="18" x2="21" y2="18" />
                            <line x1="3" y1="6" x2="3.01" y2="6" /><line x1="3" y1="12" x2="3.01" y2="12" /><line x1="3" y1="18" x2="3.01" y2="18" />
                        </svg>
                        List
                    </button>
                    <button type="button" class="ch-mobile-panels__btn is-active" data-ch-panel-btn="conversation"
                        aria-pressed="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />
                        </svg>
                        Detail
                    </button>
                    <button type="button" class="ch-mobile-panels__btn" data-ch-panel-btn="phone"
                        aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                        </svg>
                        Phone
                    </button>
                </nav>
            @endunless

            <div class="ghl-inbox-body {{ $isWidePanel ? 'ghl-inbox-body-wide' : '' }}"
                @unless ($isWidePanel) data-ch-panel="conversation" @endunless>
                @unless ($isWidePanel)
                    @include('communications.inbox.partials.list')
                @endunless

                <section class="ghl-inbox-conversation">
                    @include('communications.inbox.partials.main')
                </section>

                @unless ($isWidePanel)
                    @include('communications.inbox.partials.right-dial-rail')
                @endunless
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

            function initMobilePanels(root) {
                var scope = root || document;
                var body = scope.querySelector('.ghl-inbox-body[data-ch-panel]');
                var bar = scope.querySelector('[data-ch-mobile-panels]');
                if (!body || !bar || bar.dataset.bound === '1') return;
                bar.dataset.bound = '1';

                var storage = null;
                try { storage = window.sessionStorage; } catch (e) { storage = null; }
                var saved = storage ? storage.getItem('ch-hub-mobile-panel') : null;
                if (saved && ['list', 'conversation', 'phone'].indexOf(saved) !== -1) {
                    setPanel(saved);
                }

                bar.querySelectorAll('[data-ch-panel-btn]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        setPanel(btn.getAttribute('data-ch-panel-btn'));
                    });
                });

                body.querySelectorAll('.ghl-inbox-row').forEach(function (row) {
                    row.addEventListener('click', function () {
                        if (window.matchMedia('(max-width: 768px)').matches) {
                            setPanel('conversation');
                        }
                    });
                });

                function setPanel(name) {
                    body.setAttribute('data-ch-panel', name);
                    bar.querySelectorAll('[data-ch-panel-btn]').forEach(function (btn) {
                        var active = btn.getAttribute('data-ch-panel-btn') === name;
                        btn.classList.toggle('is-active', active);
                        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                    if (storage) {
                        try { storage.setItem('ch-hub-mobile-panel', name); } catch (e) { /* noop */ }
                    }
                }
            }

            initMobilePanels(document);
            document.addEventListener('turbo:load', function () { initMobilePanels(document); });

            document.querySelectorAll('[data-ghl-tools-close]').forEach(function (btn) {
                if (btn.dataset.bound === '1') return;
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    document.body.classList.toggle('ghl-comm-tools-hidden');
                });
            });

            var statusMain = document.querySelector('[data-webphone-status-text]');
            if (statusMain) {
                document.querySelectorAll('[data-webphone-status-text-rail]').forEach(function (el) {
                    el.textContent = statusMain.textContent || 'Off';
                });
                var observer = new MutationObserver(function () {
                    document.querySelectorAll('[data-webphone-status-text-rail]').forEach(function (el) {
                        el.textContent = statusMain.textContent || 'Off';
                    });
                });
                observer.observe(statusMain, { childList: true, characterData: true, subtree: true });
            }
        })();
    </script>
@endpush
