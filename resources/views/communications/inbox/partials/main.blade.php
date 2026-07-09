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
        <div class="ghl-inbox-panel ghl-inbox-panel--thread">
            @include('communications.inbox.partials.panels.sms')
        </div>
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
        @include('communications.inbox.partials.panels.dialer')
    @break

    @default
        <div class="ghl-inbox-conversation-scroll">
            @include('communications.inbox.partials.empty', [
                'icon' => '💬',
                'title' => 'Select a conversation',
                'message' => 'Pick a contact, call, or message from the inbox. Use Messages or Phone in the bar above.',
            ])
        </div>
@endswitch
