<form method="POST" action="{{ route($routePrefix . 'communications.zoom.sms.send') }}" class="ghl-sms-compose-form">
    @csrf
    @if (!empty($thread['session_id']))
        <input type="hidden" name="session_id" value="{{ $thread['session_id'] }}">
    @endif
    <div>
        <label class="comm-hub-label block mb-1 text-xs" for="sms-sender">From line</label>
        <select id="sms-sender" name="sender_line" class="comm-hub-input w-full comm-hub-input-sm" required>
            @foreach ($phoneUsers as $user)
                @foreach ($user['phone_numbers'] as $number)
                    <option value="{{ $user['id'] }}|{{ $number }}" @selected(old('sender_phone', $thread['owner_phone'] ?? '') === $number)>
                        {{ $user['name'] }} — {{ $number }}
                    </option>
                @endforeach
            @endforeach
        </select>
        <input type="hidden" name="sender_user_id" id="sms-sender-user-id" value="{{ old('sender_user_id') }}">
        <input type="hidden" name="sender_phone" id="sms-sender-phone"
            value="{{ old('sender_phone', $thread['owner_phone'] ?? '') }}">
    </div>
    <div>
        <label class="comm-hub-label block mb-1 text-xs" for="sms-to">To</label>
        <input type="tel" id="sms-to" name="to_phone" class="comm-hub-input w-full comm-hub-input-sm" required
            value="{{ old('to_phone', $prefillTo ?? '') }}" placeholder="+1 555 123 4567">
    </div>
    <div class="ghl-sms-compose-row">
        <textarea id="sms-message" name="message" rows="2" class="comm-hub-input" required maxlength="1600"
            placeholder="Type your message…">{{ old('message') }}</textarea>
        <button type="submit" class="comm-hub-btn comm-hub-btn-sm" style="flex-shrink:0;">Send</button>
    </div>
</form>
