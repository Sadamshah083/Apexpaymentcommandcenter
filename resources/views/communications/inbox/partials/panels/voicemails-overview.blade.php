<div class="ghl-inbox-overview">
    <h2 class="text-lg font-bold text-zinc-900 mb-1">Voicemail inbox</h2>
    <p class="text-sm text-zinc-500 mb-4">{{ count($voiceMails ?? []) }} voicemails on this page.</p>
    @include('communications.inbox.partials.empty', [
        'title' => 'Select a voicemail',
        'message' => 'Choose a voicemail from the left to listen, read transcription, or call back.',
    ])
</div>
