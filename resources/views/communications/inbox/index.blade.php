@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications')

@section('content')
    @php
        $routePrefix = $routePrefix ?? (request()->is('admin*') ? 'admin.' : 'portal.');
        $baseQuery = array_filter([
            'channel' => $channel ?? 'inbox',
            'search' => $filters['search'] ?? null,
            'filter' => $filters['filter'] ?? null,
            'direction' => $filters['direction'] ?? null,
            'status' => $filters['status'] ?? null,
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
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
        $isWidePanel = in_array($panel ?? '', ['settings', 'dialer'], true);
        $channelLabel = $channels[$channel]['label'] ?? 'Inbox';
    @endphp

    <div class="ghl-inbox ch-hub-shell">
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

        @include('communications.inbox.partials.toolbar')

        <div class="ghl-inbox-card">
            <div class="ghl-inbox-card-top">
                @include('communications.inbox.partials.nav')
            </div>

            <div class="ghl-inbox-body {{ $isWidePanel ? 'ghl-inbox-body-wide' : '' }}">
                @unless ($isWidePanel)
                    @include('communications.inbox.partials.list')
                @endunless

                <section class="ghl-inbox-conversation">
                    @include('communications.inbox.partials.main')
                </section>

                @unless ($isWidePanel)
                    @include('communications.inbox.partials.tools')
                @endunless
            </div>
        </div>
    </div>

    @include('communications.partials.audio-player')
    @include('communications.partials.webphone-floating-popup')
@endsection

@push('scripts')
    @include('communications.partials.audio-player-script')
    <script>
        (function () {
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
