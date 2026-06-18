<div class="ghl-team-grid">
    <section class="ghl-card">
        <h3 class="ghl-card-title">Zoom users ({{ count($teamUsers ?? []) }})</h3>
        <div class="ghl-team-list">
            @forelse($teamUsers ?? [] as $user)
                <div class="ghl-team-row">
                    <span class="ghl-avatar">{{ strtoupper(substr($user['name'], 0, 2)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-zinc-900">{{ $user['name'] }}</div>
                        <div class="text-xs text-zinc-500">{{ $user['email'] ?? '—' }}</div>
                    </div>
                    <span class="ghl-tag">{{ $user['status'] ?? 'active' }}</span>
                </div>
            @empty
                <p class="ghl-empty py-4">No users found.</p>
            @endforelse
        </div>
    </section>

    <section class="ghl-card">
        <h3 class="ghl-card-title">Phone lines ({{ count($phoneUsers ?? []) }})</h3>
        <div class="ghl-team-list">
            @forelse($phoneUsers ?? [] as $user)
                <div class="ghl-team-row">
                    <span class="ghl-avatar">📞</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-zinc-900">{{ $user['name'] }}</div>
                        <div class="text-xs text-zinc-500">
                            @foreach($user['phone_numbers'] as $number)
                                <a href="{{ route($routePrefix.'communications.index', ['channel' => 'inbox', 'panel' => 'dialer', 'number' => $number]) }}" class="comm-hub-link">{{ $number }}</a>@if(!$loop->last), @endif
                            @endforeach
                            @if(empty($user['phone_numbers']) && !empty($user['extension_number']))
                                ext {{ $user['extension_number'] }}
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="ghl-empty py-4">No phone users found.</p>
            @endforelse
        </div>
    </section>

    <section class="ghl-card">
        <h3 class="ghl-card-title">Call queues ({{ count($teamQueues ?? []) }})</h3>
        @if($queueWarning ?? null)
            <div class="comm-hub-alert comm-hub-alert-warning mb-3 text-xs">{{ $queueWarning }}</div>
        @endif
        <div class="ghl-team-list">
            @forelse($teamQueues ?? [] as $queue)
                <div class="ghl-team-row">
                    <span class="ghl-avatar">Q</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-zinc-900">{{ $queue['name'] ?? 'Queue' }}</div>
                        <div class="text-xs text-zinc-500">
                            ext {{ $queue['extension_number'] ?? '—' }}
                            @if(!empty($queue['phone_numbers'][0])) · {{ $queue['phone_numbers'][0] }} @endif
                        </div>
                    </div>
                    <span class="ghl-tag">{{ $queue['status'] ?? 'active' }}</span>
                </div>
            @empty
                <p class="ghl-empty py-4">No call queues found.</p>
            @endforelse
        </div>
    </section>
</div>
