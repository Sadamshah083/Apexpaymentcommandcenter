<div class="ghl-inbox-overview">
    <h2 class="text-lg font-bold text-zinc-900 mb-1">Recordings library</h2>
    <p class="text-sm text-zinc-500 mb-4">{{ count($recordings ?? []) }} recordings on this page.</p>
    @if(empty($sidebarItems))
        @include('communications.inbox.partials.zoom-data-hint')
    @endif
    @include('communications.inbox.partials.empty', [
        'title' => 'Select a recording',
        'message' => 'Pick a recording from the left to play or download.',
    ])
</div>
