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

    <div class="ghl-inbox">
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
                    @foreach ($warnings ?? [] as $warning)
                        <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
                    @endforeach
                    @if ($error)
                        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
                    @endif
                @endif
            </div>
        @endif

        @include('communications.inbox.partials.toolbar')

        <div class="ghl-inbox-card">
            @include('communications.inbox.partials.nav')

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
@endsection

@push('scripts')
    @include('communications.partials.audio-player-script')
@endpush
