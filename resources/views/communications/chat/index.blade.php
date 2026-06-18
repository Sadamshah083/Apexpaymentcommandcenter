@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Chat')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'chat', 'routePrefix' => $routePrefix])

    @if($warning)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
    @endif

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="ghl-split">
        <aside class="ghl-list-panel">
            <div class="ghl-list-toolbar">
                <h2 class="text-sm font-bold text-zinc-900">Team Chat</h2>
                <p class="text-xs text-zinc-500 mt-1">{{ count($channels) }} channels on this page</p>
            </div>

            <div class="ghl-contact-list">
                @forelse($channels as $channel)
                    <a href="{{ route($routePrefix.'communications.index', array_filter([
                        'mode' => 'chat',
                        'chat_owner' => $channel['owner_user_id'],
                        'chat_channel' => $channel['channel_id'],
                    ])) }}"
                       class="ghl-contact-row {{ ($selectedThreadKey ?? '') === $channel['thread_key'] ? 'ghl-contact-row-active' : '' }}">
                        <span class="ghl-avatar">#</span>
                        <span class="ghl-contact-meta">
                            <span class="ghl-contact-name">{{ $channel['label'] }}</span>
                            <span class="ghl-contact-sub">{{ $channel['owner_name'] }}</span>
                        </span>
                        <span class="ghl-contact-side">
                            @if($channel['last_message_sent_time'] ?? null)
                                <span class="ghl-contact-time">{{ \Carbon\Carbon::parse($channel['last_message_sent_time'])->diffForHumans(short: true) }}</span>
                            @endif
                            <span class="ghl-tag">{{ $channel['type'] ?? 'channel' }}</span>
                        </span>
                    </a>
                @empty
                    <div class="ghl-empty">
                        <p>No Team Chat channels found.</p>
                        @if(empty($error))
                            <p class="text-xs text-zinc-500 mt-2">
                                Add <code>team_chat:read:list_user_channels:admin</code> and
                                <code>team_chat:read:list_user_messages:admin</code> in Zoom Marketplace, then run
                                <code>php artisan zoom:clear-token --cache</code>.
                            </p>
                        @endif
                    </div>
                @endforelse
            </div>

            @if($nextPageToken ?? null)
                <div class="p-3 border-t border-zinc-200">
                    <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
                       class="comm-hub-btn comm-hub-btn-secondary w-full text-center">Load more channels</a>
                </div>
            @endif
        </aside>

        <main class="ghl-detail-panel">
            @if($selectedThread)
                <div class="ghl-detail-header">
                    <span class="ghl-avatar ghl-avatar-lg">#</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-xl font-bold text-zinc-900 truncate">{{ $selectedThread['label'] }}</h2>
                        <p class="text-sm text-zinc-500 mt-0.5">
                            {{ $selectedThread['owner_name'] ?? 'Zoom user' }}
                            · {{ $selectedThread['type'] ?? ($selectedThread['thread_type'] ?? 'channel') }}
                            @if(!empty($selectedThread['member_count']))
                                · {{ (int) $selectedThread['member_count'] }} members
                            @endif
                        </p>
                    </div>
                </div>

                <section class="ghl-card ghl-conversation">
                    <h3 class="ghl-card-title">Messages</h3>
                    <div class="ghl-thread">
                        @forelse($messages as $message)
                            @php
                                $isOutbound = ($message['direction'] ?? '') === 'outbound';
                            @endphp
                            <article class="ghl-message {{ $isOutbound ? 'ghl-message-out' : 'ghl-message-in' }}">
                                <div class="ghl-message-bubble">
                                    @if(!empty($message['sender']))
                                        <div class="text-xs font-semibold text-zinc-600 mb-1">{{ $message['sender'] }}</div>
                                    @endif
                                    <div class="ghl-message-body whitespace-pre-wrap">{{ $message['message'] ?: '(empty message)' }}</div>
                                    <div class="ghl-message-meta">
                                        {{ !empty($message['date_time']) ? \Carbon\Carbon::parse($message['date_time'])->format('M j, g:i A') : '—' }}
                                    </div>
                                </div>
                            </article>
                        @empty
                            <p class="ghl-empty py-8">No messages in this channel yet, or messages are outside the available history window.</p>
                        @endforelse
                    </div>

                    @if($messagesNextPageToken ?? null)
                        <div class="mt-4 text-center">
                            <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['msg_page_token' => $messagesNextPageToken])) }}"
                               class="comm-hub-btn comm-hub-btn-secondary">Load older messages</a>
                        </div>
                    @endif
                </section>
            @else
                <div class="ghl-detail-empty">
                    <div class="ghl-detail-empty-icon" aria-hidden="true">💬</div>
                    <h2 class="app-page-title text-lg">Select a channel</h2>
                    <p class="app-page-subtitle max-w-sm">Choose a Zoom Team Chat channel to read the conversation history.</p>
                </div>
            @endif
        </main>
    </div>
</div>
@endsection
