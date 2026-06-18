<section class="ghl-card" style="max-width: 480px;">
    <h3 class="ghl-card-title">New SMS message</h3>
    @if(!empty($phoneUsers))
        @include('communications.inbox.partials.sms-compose-form', [
            'thread' => null,
            'prefillTo' => request('number', ''),
        ])
        <p class="text-xs text-zinc-500 mt-3">Requires <code>phone:read:sms_message:admin</code> scope in Zoom.</p>
    @else
        <p class="text-sm text-zinc-500">No Zoom Phone lines available. Add <code>phone:read:list_users:admin</code> and refresh.</p>
    @endif
</section>

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
