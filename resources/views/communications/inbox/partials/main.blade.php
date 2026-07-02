@switch($panel)
    @case('contact')
        <div class="ghl-inbox-conversation-scroll">
            @if ($selectedContact)
                @include('communications.inbox.partials.panels.contact', [
                    'contact' => $selectedContact,
                ])
            @else
                @include('communications.inbox.partials.empty', [
                    'title' => 'Contact not found',
                    'message' => 'Try widening the date range or pick another contact from the list.',
                ])
            @endif
        </div>
    @break

    @case('sms')
        @include('communications.inbox.partials.panels.sms')
    @break

    @case('compose_sms')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.compose-sms')
        </div>
    @break

    @case('chat')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.chat')
        </div>
    @break

    @case('call')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.call')
        </div>
    @break

    @case('voicemail')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.voicemail-detail')
        </div>
    @break

    @case('recording')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.recording')
        </div>
    @break

    @case('calls')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.calls-overview')
        </div>
    @break

    @case('recordings')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.recordings-overview')
        </div>
    @break

    @case('voicemails')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.voicemails-overview')
        </div>
    @break

    @case('team')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.team')
        </div>
    @break

    @case('queues')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.queues')
        </div>
    @break

    @case('conferences')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.conferences')
        </div>
    @break

    @case('leads')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.leads')
        </div>
    @break

    @case('campaigns')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.campaigns')
        </div>
    @break

    @case('lists')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.lists')
        </div>
    @break

    @case('extensions')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.extensions')
        </div>
    @break

    @case('agents')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.agents')
        </div>
    @break

    @case('settings')
        <div class="ghl-inbox-conversation-scroll ghl-inbox-settings">
            @include('communications.inbox.partials.panels.settings')
        </div>
    @break

    @case('dialer')
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.panels.dialer')
        </div>
    @break

    @default
        <div class="ghl-inbox-empty">
            <div class="ghl-inbox-empty-icon">💬</div>
            <h2>Select a conversation</h2>
            <p>Choose someone from the {{ strtolower($channels[$channel]['label'] ?? 'inbox') }} list, or use the tools on the
                right to place a call.</p>
            <div class="ghl-inbox-empty-actions">
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'inbox']) }}"
                    class="comm-hub-btn comm-hub-btn-secondary comm-hub-btn-sm">Open inbox</a>
                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'dialer'])) }}"
                    class="comm-hub-btn comm-hub-btn-sm">Open dialer</a>
                @if ($channel === 'sms')
                    <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'compose_sms'])) }}"
                        class="comm-hub-btn comm-hub-btn-sm">New SMS</a>
                @endif
            </div>
        </div>
@endswitch
