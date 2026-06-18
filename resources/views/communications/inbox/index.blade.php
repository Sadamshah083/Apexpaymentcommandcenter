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
    @if(!empty($warnings) || !empty($error))
        <div class="ghl-inbox-alerts">
            @foreach($warnings ?? [] as $warning)
                <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
            @endforeach
            @if($error)
                <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
            @endif
        </div>
    @endif

    @include('communications.inbox.partials.toolbar')

    <div class="ghl-inbox-card">
        @include('communications.inbox.partials.nav')

        <div class="ghl-inbox-body {{ $isWidePanel ? 'ghl-inbox-body-wide' : '' }}">
            @unless($isWidePanel)
                @include('communications.inbox.partials.list')
            @endunless

            <section class="ghl-inbox-conversation">
                @include('communications.inbox.partials.main')
            </section>

            @unless($isWidePanel)
                @include('communications.inbox.partials.tools')
            @endunless
        </div>
    </div>
</div>

@include('communications.partials.audio-player')
@endsection

@push('scripts')
@include('communications.partials.audio-player-script')
@include('communications.inbox.partials.dialer-script')
@endpush
