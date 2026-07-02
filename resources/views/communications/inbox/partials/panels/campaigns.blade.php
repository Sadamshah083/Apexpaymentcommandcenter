@php $items = $morpheusCampaigns ?? []; @endphp

<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create campaign</h3>
    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.campaigns.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        @csrf
        <input type="text" name="name" placeholder="Campaign name *" required class="comm-hub-input">
        <select name="dial_mode" class="comm-hub-input">
            <option value="">Dial mode</option>
            @foreach(['manual', 'ratio', 'inbound', 'blended'] as $mode)
                <option value="{{ $mode }}">{{ ucfirst($mode) }}</option>
            @endforeach
        </select>
        <select name="status" class="comm-hub-input">
            <option value="">Status</option>
            @foreach(['draft', 'active', 'paused', 'completed', 'archived'] as $status)
                <option value="{{ $status }}">{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button type="submit" class="comm-hub-btn">Create</button>
    </form>
</div>

<div class="ghl-card">
    <h3 class="ghl-card-title">Campaigns ({{ count($items) }})</h3>
    <div class="space-y-2 mt-3">
        @forelse($items as $campaign)
                <div class="flex items-center justify-between p-3 rounded-lg border border-slate-100">
                <div>
                    <div class="font-semibold">{{ $campaign['name'] ?? 'Campaign' }}</div>
                    <div class="text-xs text-slate-500">{{ $campaign['dial_mode'] ?? '—' }} · {{ $campaign['status'] ?? '—' }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.campaigns.update', ['id' => $campaign['id']]) }}" class="flex gap-1">
                        @csrf @method('PATCH')
                        <select name="status" class="comm-hub-input text-xs py-1">
                            @foreach(['draft', 'active', 'paused', 'completed', 'archived'] as $status)
                                <option value="{{ $status }}" @selected(($campaign['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="comm-hub-btn text-xs py-1 px-2">Update</button>
                    </form>
                    <form method="POST" action="{{ route($routePrefix.'communications.morpheus.campaigns.destroy', ['id' => $campaign['id']]) }}" onsubmit="return confirm('Delete campaign?')">@csrf @method('DELETE')<button type="submit" class="comm-hub-link text-xs text-red-600">Delete</button></form>
                </div>
            </div>
        @empty
            <p class="ghl-empty py-6">No campaigns.</p>
        @endforelse
    </div>
</div>
