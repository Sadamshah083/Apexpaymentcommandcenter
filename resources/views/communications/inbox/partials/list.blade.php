<aside class="ghl-inbox-list">
    <div class="ghl-inbox-list-header">
        <div class="ghl-comm-inbox-head">
            <h2 class="ghl-comm-inbox-head__title">Team inbox</h2>
            <span class="ghl-comm-inbox-head__count">{{ $listPagination['total'] ?? count($sidebarItems) }}</span>
        </div>

        <form method="GET" action="{{ route($routePrefix . 'communications.index') }}" class="ghl-comm-inbox-search">
            <input type="hidden" name="channel" value="{{ $channel }}">
            @foreach (request()->only(['contact', 'session', 'call', 'voicemail', 'recording', 'chat_owner', 'chat_channel', 'chat_contact', 'filter', 'direction', 'status', 'from', 'to']) as $key => $value)
                @if (filled($value))
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endif
            @endforeach
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" />
            </svg>
            <input type="search" name="search" value="{{ $filters['search'] ?? '' }}"
                placeholder="Search…" aria-label="Search">
        </form>

        @if ($channel === 'inbox')
            <div class="ghl-filter-pills">
                @foreach (['' => 'All', 'recent' => 'Recent', 'with_phone' => 'Phone'] as $value => $label)
                    <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['filter' => $value ?: null])) }}"
                        class="ghl-pill {{ ($filters['filter'] ?? '') === $value ? 'ghl-pill-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        @elseif($channel === 'calls')
            <div class="ghl-filter-pills">
                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['filter' => null, 'direction' => null])) }}"
                    class="ghl-pill {{ ($filters['filter'] ?? '') === '' && ($filters['direction'] ?? '') === '' ? 'ghl-pill-active' : '' }}">All</a>
                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['direction' => 'inbound'])) }}"
                    class="ghl-pill {{ ($filters['direction'] ?? '') === 'inbound' ? 'ghl-pill-active' : '' }}">In</a>
                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['direction' => 'outbound'])) }}"
                    class="ghl-pill {{ ($filters['direction'] ?? '') === 'outbound' ? 'ghl-pill-active' : '' }}">Out</a>
                <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['filter' => 'missed', 'direction' => null])) }}"
                    class="ghl-pill {{ ($filters['filter'] ?? '') === 'missed' ? 'ghl-pill-active' : '' }}">Missed</a>
            </div>
        @elseif($channel === 'voicemail')
            <form method="GET">
                <input type="hidden" name="channel" value="voicemail">
                <input type="hidden" name="from" value="{{ $filters['from'] }}">
                <input type="hidden" name="to" value="{{ $filters['to'] }}">
                <select name="status" class="comm-hub-input comm-hub-input-sm w-full" onchange="this.form.submit()">
                    @foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label)
                        <option value="{{ $value }}"
                            {{ ($filters['status'] ?? 'all') === $value ? 'selected' : '' }}>{{ $label }}
                        </option>
                    @endforeach
                </select>
            </form>
        @elseif($channel === 'sms')
            <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'compose_sms'])) }}"
                class="ghl-comm-btn ghl-comm-btn--primary w-full text-center">+ New</a>
        @endif
    </div>

    <div class="ghl-inbox-list-scroll">
        @forelse($sidebarItems as $item)
            @php
                $avatarClass = match ($item['kind'] ?? 'contact') {
                    'call' => 'call',
                    'sms' => 'sms',
                    'voicemail' => 'vm',
                    default => 'contact',
                };
            @endphp
            <a href="{{ $item['url'] }}"
                class="ghl-inbox-row {{ !empty($item['active']) ? 'ghl-inbox-row-active' : '' }}">
                <span
                    class="ghl-inbox-row-avatar ghl-inbox-row-avatar-{{ $avatarClass }}">{{ $item['avatar'] }}</span>
                <span class="ghl-inbox-row-body">
                    <span class="ghl-inbox-row-name" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                    <span class="ghl-inbox-row-preview" title="{{ $item['subtitle'] }}">{{ $item['subtitle'] }}</span>
                </span>
                <span class="ghl-inbox-row-meta">
                    @if (!empty($item['time']))
                        <span
                            class="ghl-inbox-row-time"
                            title="{{ \Carbon\Carbon::parse($item['time'])->format('M j, Y g:i A') }}">{{ \Carbon\Carbon::parse($item['time'])->diffForHumans(short: true) }}</span>
                    @endif
                    @if (!empty($item['badge']))
                        <span
                            class="ghl-tag {{ ($item['badge'] ?? '') === 'unread' ? 'ghl-tag-unread' : '' }}">{{ $item['badge'] }}</span>
                    @endif
                </span>
            </a>
        @empty
            <div class="ghl-inbox-empty" style="min-height: 10rem; padding: 1.5rem 0.75rem;">
                @if (($channel ?? '') === 'calls' || ($channel ?? '') === 'recordings')
                    @include('communications.inbox.partials.zoom-data-hint')
                @endif
                <p style="font-size: 0.75rem; color: #8792a2;">No {{ strtolower($channelLabel) }} in this range.</p>
                @if (!empty($warnings) && !in_array($channel ?? '', ['calls', 'recordings'], true))
                    <p class="text-xs text-amber-700 mt-2 max-w-xs mx-auto">{{ $warnings[0] }}</p>
                @endif
            </div>
        @endforelse
    </div>

    <x-communications.list-pagination :pagination="$listPagination ?? null" />
</aside>
