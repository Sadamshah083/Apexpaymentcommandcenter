@if($smsSession ?? null)
    @php $thread = $smsSession; @endphp
    <div class="ghl-conv-header">
        <span class="ghl-inbox-row-avatar ghl-inbox-row-avatar-sms">SMS</span>
        <div class="ghl-conv-header-info">
            <h2 class="ghl-conv-header-name">{{ $thread['label'] }}</h2>
            <p class="ghl-conv-header-sub">{{ $thread['owner_phone'] ?? '—' }} ↔ {{ $thread['other_phone'] ?? '—' }}</p>
        </div>
        @if(!empty($thread['other_phone']))
            @include('communications.partials.contact-quick-actions', [
                'routePrefix' => $routePrefix,
                'phone' => $thread['other_phone'],
                'smsSession' => $thread,
            ])
        @endif
    </div>

    <div class="ghl-sms-thread">
        @forelse($smsMessages ?? [] as $message)
            @php $isOutbound = in_array($message['direction'] ?? '', ['outbound', 'out'], true); @endphp
            <div class="ghl-sms-bubble {{ $isOutbound ? 'ghl-sms-bubble-out' : 'ghl-sms-bubble-in' }}">
                <div>{{ $message['message'] ?: '(attachment)' }}</div>
                <div class="ghl-sms-meta">
                    {{ !empty($message['date_time']) ? \Carbon\Carbon::parse($message['date_time'])->format('M j, g:i A') : '' }}
                    · {{ $message['delivery_status'] ?? '' }}
                </div>
            </div>
        @empty
            <p class="ghl-empty py-8 text-center">No messages yet. Send one below.</p>
        @endforelse
        @if($smsMessagesNextPageToken ?? null)
            <div class="text-center py-2">
                <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['msg_page_token' => $smsMessagesNextPageToken])) }}"
                   class="comm-hub-link text-sm">Load older messages</a>
            </div>
        @endif
    </div>

    @if(!empty($phoneUsers))
        <div class="ghl-sms-compose">
            @include('communications.inbox.partials.sms-compose-form', [
                'thread' => $thread,
                'prefillTo' => $thread['other_phone'] ?? '',
            ])
        </div>
    @endif
@else
    @include('communications.inbox.partials.empty', ['title' => 'Thread not found', 'message' => 'Select an SMS conversation from the list.'])
@endif

@push('scripts')
<script>
(function () {
    const senderSelect = document.getElementById('sms-sender');
    const userIdInput = document.getElementById('sms-sender-user-id');
    const phoneInput = document.getElementById('sms-sender-phone');
    if (!senderSelect || !userIdInput || !phoneInput) return;
    function sync() {
        const [userId, phone] = (senderSelect.value || '|').split('|');
        userIdInput.value = userId || '';
        phoneInput.value = phone || '';
    }
    senderSelect.addEventListener('change', sync);
    sync();
})();
</script>
@endpush
