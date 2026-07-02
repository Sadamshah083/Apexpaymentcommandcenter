@php
    $items = $morpheusConferences ?? [];
    $selectedId = request('conference');
    $members = $selectedConferenceMembers ?? [];
@endphp

<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create conference room</h3>
    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.conferences.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        @csrf
        <input type="text" name="name" placeholder="Room name" required class="comm-hub-input">
        <input type="text" name="extension_num" placeholder="Extension" class="comm-hub-input">
        <input type="text" name="pin" placeholder="PIN" class="comm-hub-input">
        <button type="submit" class="comm-hub-btn">Create room</button>
    </form>
</div>

@if($selectedId)
    <div class="ghl-card mb-6">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h3 class="ghl-card-title">Live roster</h3>
            <form method="POST" action="{{ route($routePrefix.'communications.morpheus.conferences.kick-all', ['id' => $selectedId]) }}" onsubmit="return confirm('Remove all members?')">
                @csrf
                <button type="submit" class="comm-hub-btn comm-hub-btn-secondary text-xs">Kick all</button>
            </form>
        </div>
        @forelse($members as $member)
            @php $memberId = $member['id'] ?? $member['member'] ?? ''; @endphp
            <div class="flex flex-wrap items-center gap-2 py-2 border-b border-slate-100 text-sm">
                <span class="font-medium">{{ $member['caller_id_name'] ?? $member['name'] ?? $memberId }}</span>
                @foreach(['mute', 'unmute', 'deaf', 'undeaf', 'kick'] as $action)
                    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.conferences.member-action', ['id' => $selectedId, 'member' => $memberId, 'action' => $action]) }}">
                        @csrf
                        <button type="submit" class="comm-hub-btn comm-hub-btn-secondary text-xs py-0.5 px-2">{{ ucfirst($action) }}</button>
                    </form>
                @endforeach
            </div>
        @empty
            <p class="text-sm text-slate-500">No members in this room.</p>
        @endforelse
    </div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">Conference rooms ({{ count($items) }})</h3>
    <div class="space-y-2 mt-3">
        @forelse($items as $room)
            <div class="flex items-center justify-between p-3 rounded-lg border border-slate-100">
                <div>
                    <div class="font-semibold">{{ $room['name'] ?? 'Room' }}</div>
                    <div class="text-xs text-slate-500">Ext {{ $room['extension_num'] ?? '—' }}</div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route($routePrefix.'communications.index', ['channel' => 'conferences', 'conference' => $room['id']]) }}" class="comm-hub-link text-xs">Roster</a>
                    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.conferences.destroy', ['id' => $room['id']]) }}" onsubmit="return confirm('Delete room?')">@csrf @method('DELETE')<button type="submit" class="comm-hub-link text-xs text-red-600">Delete</button></form>
                </div>
            </div>
        @empty
            <p class="ghl-empty py-6">No conference rooms.</p>
        @endforelse
    </div>
</div>
