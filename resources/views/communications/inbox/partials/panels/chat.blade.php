@if($selectedThread ?? null)
    <div class="ghl-detail-header">
        <span class="ghl-avatar ghl-avatar-lg">#</span>
        <div class="min-w-0 flex-1">
            <h2 class="text-xl font-bold text-zinc-900 truncate">{{ $selectedThread['label'] }}</h2>
            <p class="text-sm text-zinc-500 mt-0.5">{{ $selectedThread['owner_name'] ?? 'Team Chat' }}</p>
        </div>
    </div>

    <section class="ghl-card ghl-conversation">
        <h3 class="ghl-card-title">Messages</h3>
        <div class="ghl-thread">
            @forelse($chatMessages ?? [] as $message)
                @php $isOutbound = ($message['direction'] ?? '') === 'outbound'; @endphp
                <article class="ghl-message {{ $isOutbound ? 'ghl-message-out' : 'ghl-message-in' }}">
                    <div class="ghl-message-bubble">
                        <div class="ghl-message-title">{{ $message['sender_name'] ?? 'Member' }}</div>
                        <div class="ghl-message-body whitespace-pre-wrap">{{ $message['message'] ?? '' }}</div>
                        <div class="ghl-message-meta">
                            {{ !empty($message['date_time']) ? \Carbon\Carbon::parse($message['date_time'])->format('M j, g:i A') : '—' }}
                        </div>
                    </div>
                </article>
            @empty
                <p class="ghl-empty py-8">No messages in this channel yet.</p>
            @endforelse
        </div>
        @if($chatMessagesNextPageToken ?? null)
            <div class="mt-4 text-center">
                <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['msg_page_token' => $chatMessagesNextPageToken])) }}"
                   class="comm-hub-btn comm-hub-btn-secondary">Load older messages</a>
            </div>
        @endif
    </section>
@else
    @include('communications.inbox.partials.empty', ['title' => 'Select a chat channel', 'message' => 'Choose a Team Chat channel from the list.'])
@endif
