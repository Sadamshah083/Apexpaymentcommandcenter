@php
    $items = $morpheusLists ?? [];
    $campaigns = $morpheusCampaigns ?? [];
@endphp

@if ($hubAccess['canConfigure'] ?? false)
<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create lead list</h3>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.lists.store') }}"
        class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        @csrf
        <input type="text" name="name" placeholder="List name *" required class="comm-hub-input">
        <input type="text" name="description" placeholder="Description" class="comm-hub-input">
        <select name="campaign_id" class="comm-hub-input">
            <option value="">Campaign (optional)</option>
            @foreach ($campaigns as $campaign)
                <option value="{{ $campaign['id'] }}">{{ $campaign['name'] ?? 'Campaign' }}</option>
            @endforeach
        </select>
        <button type="submit" class="comm-hub-btn">Create list</button>
    </form>
</div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">Lead lists ({{ count($items) }})</h3>
    <div class="space-y-2 mt-3">
        @forelse($items as $list)
            <div class="flex items-center justify-between p-3 border border-slate-100 rounded-lg">
                <div>
                    <div class="font-semibold">{{ $list['name'] ?? 'List' }}</div>
                    <div class="text-xs text-slate-500">{{ $list['status'] ?? '—' }}</div>
                </div>
                <form method="POST"
                    action="{{ route($routePrefix . 'communications.morpheus.lists.destroy', ['id' => $list['id']]) }}"
                    onsubmit="return confirm('Delete list?')">@csrf @method('DELETE')<button type="submit"
                        class="comm-hub-link text-xs text-red-600">Delete</button></form>
            </div>
        @empty
            <p class="ghl-empty py-6">No lists.</p>
        @endforelse
    </div>
    <x-communications.list-pagination :pagination="$panelPagination ?? null" class="mt-4" />
</div>
