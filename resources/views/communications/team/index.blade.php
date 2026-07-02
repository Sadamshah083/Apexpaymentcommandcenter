@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Team')

@section('content')
<div class="ghl-hub">
    @include('communications.partials.hub-tabs', ['mode' => 'team', 'routePrefix' => $routePrefix])

    @if($queueWarning)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $queueWarning }}</div>
    @endif

    @if($error)
        <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
    @endif

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <section class="app-card app-card-padded">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 class="text-lg font-bold text-zinc-900">Morpheus users</h2>
                    <p class="text-sm text-zinc-500">{{ count($users) }} active users</p>
                </div>
            </div>

            <x-data-table>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Type</th>
                            <th>Last login</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td class="font-semibold text-zinc-900">{{ $user['name'] }}</td>
                                <td class="text-zinc-600">{{ $user['email'] ?? '—' }}</td>
                                <td><span class="ghl-tag">{{ $user['type'] ?? 'user' }}</span></td>
                                <td class="text-zinc-500 text-sm">
                                    @if($user['last_login_time'])
                                        {{ \Carbon\Carbon::parse($user['last_login_time'])->diffForHumans() }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="ghl-empty py-8">No Morpheus users returned.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </section>

        <section class="app-card app-card-padded">
            <div class="mb-4">
                <h2 class="text-lg font-bold text-zinc-900">Call queues</h2>
                <p class="text-sm text-zinc-500">{{ count($queues) }} queues</p>
            </div>

            <div class="space-y-3">
                @forelse($queues as $queue)
                    <article class="ghl-call-card !flex-col !items-stretch">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-bold text-zinc-900">{{ $queue['name'] }}</h3>
                                <p class="text-xs text-zinc-500 mt-1">
                                    Ext {{ $queue['extension_number'] ?? '—' }}
                                    @if($queue['site'])
                                        · {{ $queue['site'] }}
                                    @endif
                                </p>
                            </div>
                            <span class="ghl-tag">{{ $queue['status'] ?? 'unknown' }}</span>
                        </div>
                        @if(!empty($queue['phone_numbers']))
                            <div class="flex flex-wrap gap-2 mt-3">
                                @foreach($queue['phone_numbers'] as $number)
                                    <span class="text-xs font-semibold px-2 py-1 rounded-lg bg-zinc-100 text-zinc-700">{{ $number }}</span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="ghl-empty py-8">No call queues found. Check Morpheus CX queue configuration.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
