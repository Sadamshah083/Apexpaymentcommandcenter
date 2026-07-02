@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — SMS')

@section('content')
    <div class="ghl-hub">
        @include('communications.partials.hub-tabs', ['mode' => 'sms', 'routePrefix' => $routePrefix])

        @if ($warning)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
        @endif

        @if ($error)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
        @endif

        <div class="ghl-split">
            <aside class="ghl-list-panel">
                <div class="ghl-list-toolbar">
                    <h2 class="text-sm font-bold text-zinc-900">SMS conversations</h2>
                    <p class="text-xs text-zinc-500 mt-1">{{ count($sessions) }} sessions on this page</p>
                </div>

                <div class="ghl-contact-list">
                    @forelse($sessions as $session)
                        <a href="{{ route($routePrefix . 'communications.index', ['mode' => 'sms', 'session' => $session['session_id']]) }}"
                            class="ghl-contact-row {{ ($selectedSessionId ?? '') === $session['session_id'] ? 'ghl-contact-row-active' : '' }}">
                            <span class="ghl-avatar">SMS</span>
                            <span class="ghl-contact-meta">
                                <span class="ghl-contact-name">{{ $session['label'] }}</span>
                                <span class="ghl-contact-sub">
                                    {{ $session['owner_phone'] ?? '—' }}
                                    @if ($session['other_phone'])
                                        ↔ {{ $session['other_phone'] }}
                                    @endif
                                </span>
                            </span>
                            <span class="ghl-contact-side">
                                @if ($session['last_access_time'])
                                    <span
                                        class="ghl-contact-time">{{ \Carbon\Carbon::parse($session['last_access_time'])->diffForHumans(short: true) }}</span>
                                @endif
                                <span class="ghl-tag">{{ $session['session_type'] ?? 'user' }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="ghl-empty">
                            <p>No SMS sessions found.</p>
                            @if (empty($error))
                                <p class="text-xs text-zinc-500 mt-2">
                                    SMS is not available through the Morpheus CX Call-Control API.
                                </p>
                            @endif
                        </div>
                    @endforelse
                </div>

                @if ($nextPageToken ?? null)
                    <div class="p-3 border-t border-zinc-200">
                        <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
                            class="comm-hub-btn comm-hub-btn-secondary w-full text-center">Load more sessions</a>
                    </div>
                @endif
            </aside>

            <main class="ghl-detail-panel">
                @if ($selectedSession)
                    <div class="ghl-detail-header">
                        <span class="ghl-avatar ghl-avatar-lg">SMS</span>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-xl font-bold text-zinc-900 truncate">{{ $selectedSession['label'] }}</h2>
                            <p class="text-sm text-zinc-500 mt-0.5">{{ $selectedSession['session_type'] ?? 'user' }}
                                session</p>
                        </div>
                        @if (!empty($selectedSession['other_phone']))
                            @include('communications.partials.contact-quick-actions', [
                                'routePrefix' => $routePrefix,
                                'phone' => $selectedSession['other_phone'],
                                'smsSession' => $selectedSession,
                            ])
                        @endif
                    </div>

                    <section class="ghl-card ghl-conversation">
                        <h3 class="ghl-card-title">Messages</h3>
                        <div class="ghl-thread">
                            @forelse($messages as $message)
                                @php
                                    $isOutbound =
                                        in_array($message['direction'] ?? '', ['outbound', 'out'], true) ||
                                        ($message['delivery_status'] ?? '') === 'sent';
                                @endphp
                                <article class="ghl-message {{ $isOutbound ? 'ghl-message-out' : 'ghl-message-in' }}">
                                    <div class="ghl-message-bubble">
                                        <div class="ghl-message-body whitespace-pre-wrap">
                                            {{ $message['message'] ?: '(attachment or empty message)' }}</div>
                                        <div class="ghl-message-meta">
                                            {{ !empty($message['date_time']) ? \Carbon\Carbon::parse($message['date_time'])->format('M j, g:i A') : '—' }}
                                            · {{ $message['delivery_status'] ?? 'unknown' }}
                                        </div>
                                        @if (!empty($message['attachments']))
                                            <div class="ghl-message-actions">
                                                @foreach ($message['attachments'] as $attachment)
                                                    @if (!empty($attachment['download_url']))
                                                        <a href="{{ $attachment['download_url'] }}" class="comm-hub-link"
                                                            target="_blank" rel="noopener">
                                                            {{ $attachment['name'] ?? 'Attachment' }}
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <p class="ghl-empty py-8">No messages in this session yet.</p>
                            @endforelse
                        </div>

                        @if ($messagesNextPageToken ?? null)
                            <div class="mt-4 text-center">
                                <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->query(), ['msg_page_token' => $messagesNextPageToken])) }}"
                                    class="comm-hub-btn comm-hub-btn-secondary">Load older messages</a>
                            </div>
                        @endif
                    </section>

                    @if (!empty($phoneUsers))
                        <section class="ghl-card mt-4">
                            <h3 class="ghl-card-title">Send SMS</h3>
                            <form method="POST" action="{{ route($routePrefix . 'communications.zoom.sms.send') }}"
                                class="space-y-3">
                                @csrf
                                <input type="hidden" name="session_id" value="{{ $selectedSession['session_id'] ?? '' }}">
                                <div>
                                    <label class="comm-hub-label block mb-1" for="sms-sender">From</label>
                                    <select id="sms-sender" name="sender_line" class="comm-hub-input w-full" required>
                                        @foreach ($phoneUsers as $user)
                                            @foreach ($user['phone_numbers'] as $number)
                                                <option value="{{ $user['id'] }}|{{ $number }}"
                                                    @selected(old('sender_phone', $selectedSession['owner_phone'] ?? '') === $number)>
                                                    {{ $user['name'] }} — {{ $number }}
                                                </option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                    <input type="hidden" name="sender_user_id" id="sms-sender-user-id"
                                        value="{{ old('sender_user_id') }}">
                                    <input type="hidden" name="sender_phone" id="sms-sender-phone"
                                        value="{{ old('sender_phone', $selectedSession['owner_phone'] ?? '') }}">
                                </div>
                                <div>
                                    <label class="comm-hub-label block mb-1" for="sms-to">To</label>
                                    <input type="tel" id="sms-to" name="to_phone" class="comm-hub-input w-full"
                                        required value="{{ old('to_phone', $selectedSession['other_phone'] ?? '') }}"
                                        placeholder="+1 555 123 4567">
                                </div>
                                <div>
                                    <label class="comm-hub-label block mb-1" for="sms-message">Message</label>
                                    <textarea id="sms-message" name="message" rows="3" class="comm-hub-input w-full" required maxlength="1600"
                                        placeholder="Type your message…">{{ old('message') }}</textarea>
                                </div>
                                <p class="text-xs text-zinc-500">SMS is not available through the Morpheus CX Call-Control
                                    API.</p>
                                <button type="submit" class="comm-hub-btn">Send SMS</button>
                            </form>
                        </section>
                    @endif
                @else
                    <div class="ghl-detail-empty">
                        <div class="ghl-detail-empty-icon" aria-hidden="true">💬</div>
                        <h2 class="app-page-title text-lg">Select a conversation</h2>
                        <p class="app-page-subtitle max-w-sm">Choose an SMS thread to read the message history.</p>
                    </div>
                @endif
            </main>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function() {
            const senderSelect = document.getElementById('sms-sender');
            const userIdInput = document.getElementById('sms-sender-user-id');
            const phoneInput = document.getElementById('sms-sender-phone');

            if (!senderSelect || !userIdInput || !phoneInput) {
                return;
            }

            function syncSenderFields() {
                const [userId, phone] = (senderSelect.value || '|').split('|');
                userIdInput.value = userId || '';
                phoneInput.value = phone || '';
            }

            senderSelect.addEventListener('change', syncSenderFields);
            syncSenderFields();
        })();
    </script>
@endpush
