<div class="ghl-card mb-6">
    <h3 class="ghl-card-title">Create Morpheus user</h3>
    <form method="POST" action="{{ route($routePrefix . 'communications.morpheus.users.store') }}"
        class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
        @csrf
        <input type="text" name="username" placeholder="Username *" required class="comm-hub-input">
        <input type="password" name="password" placeholder="Password (min 8) *" required minlength="8"
            class="comm-hub-input">
        <input type="email" name="email" placeholder="Email" class="comm-hub-input">
        <input type="text" name="first_name" placeholder="First name" class="comm-hub-input">
        <input type="text" name="last_name" placeholder="Last name" class="comm-hub-input">
        <select name="role" class="comm-hub-input">
            <option value="user">User</option>
            <option value="admin">Admin</option>
        </select>
        <button type="submit" class="comm-hub-btn md:col-span-3">Create user</button>
    </form>
</div>

<div class="ghl-team-grid">
    <section class="ghl-card">
        <h3 class="ghl-card-title">Morpheus users ({{ count($teamUsers ?? []) }})</h3>
        <div class="ghl-team-list">
            @forelse($teamUsers ?? [] as $user)
                <div class="ghl-team-row">
                    <span class="ghl-avatar">{{ strtoupper(substr($user['name'], 0, 2)) }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-zinc-900">{{ $user['name'] }}</div>
                        <div class="text-xs text-zinc-500">{{ $user['email'] ?? '—' }}</div>
                    </div>
                    <span class="ghl-tag">{{ $user['status'] ?? 'active' }}</span>
                    <form method="POST"
                        action="{{ route($routePrefix . 'communications.morpheus.users.destroy', ['id' => $user['id']]) }}"
                        onsubmit="return confirm('Delete user?')">@csrf @method('DELETE')<button type="submit"
                            class="comm-hub-link text-xs text-red-600">Delete</button></form>
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
                            @foreach ($user['phone_numbers'] as $number)
                                <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'inbox', 'panel' => 'dialer', 'number' => $number]) }}"
                                    class="comm-hub-link">{{ $number }}</a>
                                @if (!$loop->last)
                                    ,
                                @endif
                            @endforeach
                            @if (empty($user['phone_numbers']) && !empty($user['extension_number']))
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
        <div class="ghl-team-list">
            @forelse($teamQueues ?? [] as $queue)
                <div class="ghl-team-row">
                    <span class="ghl-avatar">Q</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-sm text-zinc-900">{{ $queue['name'] ?? 'Queue' }}</div>
                        <div class="text-xs text-zinc-500">{{ $queue['waiting'] ?? 0 }} waiting</div>
                    </div>
                    <a href="{{ route($routePrefix . 'communications.index', ['channel' => 'queues', 'queue' => $queue['id']]) }}"
                        class="comm-hub-link text-xs">Manage</a>
                </div>
            @empty
                <p class="ghl-empty py-4">No call queues found.</p>
            @endforelse
        </div>
    </section>
</div>
