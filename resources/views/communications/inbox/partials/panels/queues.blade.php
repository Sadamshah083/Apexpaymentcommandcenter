@php
    $items = $morpheusQueues ?? [];
    $selectedId = request('queue');
    $selected = collect($items)->firstWhere('id', $selectedId);
    $waiting = $selectedQueueWaiting ?? [];
@endphp

<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create queue</h3>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.queues.store') }}"
        class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-3">
        @csrf
        <input type="text" name="name" placeholder="Queue name" required class="comm-hub-input">
        <input type="text" name="description" placeholder="Description" class="comm-hub-input">
        <input type="text" name="strategy" placeholder="Strategy" class="comm-hub-input">
        <button type="submit" class="comm-hub-btn">Create queue</button>
    </form>
</div>

@if ($selected)
    <div class="ghl-card mb-6">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="ghl-card-title">{{ $selected['name'] ?? 'Queue' }}</h3>
                <p class="text-sm text-slate-500">{{ $selected['waiting'] ?? 0 }} waiting · longest
                    {{ $selected['longest_wait_sec'] ?? 0 }}s</p>
            </div>
            <form method="POST"
                action="{{ route($routePrefix . 'communications.morpheus.queues.destroy', ['id' => $selected['id']]) }}"
                onsubmit="return confirm('Delete this queue?')">
                @csrf @method('DELETE')
                <button type="submit" class="comm-hub-btn comm-hub-btn-secondary text-xs">Delete</button>
            </form>
        </div>
        <form method="POST"
            action="{{ route($routePrefix . 'communications.morpheus.queues.update', ['id' => $selected['id']]) }}"
            class="grid grid-cols-1 md:grid-cols-4 gap-2 mt-4">
            @csrf @method('PATCH')
            <input type="text" name="name" value="{{ $selected['name'] ?? '' }}" class="comm-hub-input text-sm">
            <input type="text" name="description" value="{{ $selected['description'] ?? '' }}"
                class="comm-hub-input text-sm">
            <input type="text" name="status" value="{{ $selected['status'] ?? '' }}" placeholder="Status"
                class="comm-hub-input text-sm">
            <button type="submit" class="comm-hub-btn text-sm">Update queue</button>
        </form>
        @if (!empty($waiting))
            <ul class="mt-3 space-y-2 text-sm">
                @foreach ($waiting as $caller)
                    <li class="p-2 rounded bg-slate-50">
                        {{ $caller['phone_number'] ?? ($caller['caller_name'] ?? 'Caller') }} ·
                        {{ $caller['wait_sec'] ?? 0 }}s</li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-slate-500 mt-3">No callers waiting.</p>
        @endif
    </div>
@endif

<div class="ghl-card">
    <h3 class="ghl-card-title">All queues ({{ count($items) }})</h3>
    <div class="space-y-2 mt-3">
        @forelse($items as $queue)
            <div class="flex flex-wrap items-center justify-between gap-2 p-3 rounded-lg border border-slate-100">
                <div>
                    <div class="font-semibold">{{ $queue['name'] ?? 'Queue' }}</div>
                    <div class="text-xs text-slate-500">{{ $queue['waiting'] ?? 0 }} waiting ·
                        {{ $queue['status'] ?? '—' }}</div>
                </div>
                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'queues', 'queue' => $queue['id']]) }}"
                    class="comm-hub-link text-xs">View waiting</a>
            </div>
        @empty
            <p class="ghl-empty py-6">No queues configured.</p>
        @endforelse
    </div>
</div>
