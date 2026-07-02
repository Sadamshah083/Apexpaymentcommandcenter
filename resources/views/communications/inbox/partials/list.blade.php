<aside class="ghl-inbox-list">
    <div class="ghl-inbox-list-header">
        <div class="ghl-inbox-list-title">{{ $channelLabel }} · {{ count($sidebarItems) }}</div>
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
                    @foreach (['all' => 'All voicemails', 'unread' => 'Unread only', 'read' => 'Read only'] as $value => $label)
                        <option value="{{ $value }}"
                            {{ ($filters['status'] ?? 'all') === $value ? 'selected' : '' }}>{{ $label }}
                        </option>
                    @endforeach
                </select>
            </form>
        @elseif($channel === 'sms')
            <a href="{{ route($routePrefix . 'communications.index', array_merge($baseQuery, ['panel' => 'compose_sms'])) }}"
                class="comm-hub-btn comm-hub-btn-sm w-full text-center">+ New message</a>
        @endif
    </div>

    <div class="ghl-inbox-list-scroll">
        @forelse($sidebarItems as $item)
            <a href="{{ $item['url'] }}"
                class="ghl-inbox-row {{ !empty($item['active']) ? 'ghl-inbox-row-active' : '' }}">
                <span
                    class="ghl-inbox-row-avatar ghl-inbox-row-avatar-{{ $item['kind'] ?? 'contact' }}">{{ $item['avatar'] }}</span>
                <span class="ghl-inbox-row-body">
                    <span class="ghl-inbox-row-name">{{ $item['label'] }}</span>
                    <span class="ghl-inbox-row-preview">{{ $item['subtitle'] }}</span>
                </span>
                <span class="ghl-inbox-row-meta">
                    @if (!empty($item['time']))
                        <span
                            class="ghl-inbox-row-time">{{ \Carbon\Carbon::parse($item['time'])->diffForHumans(short: true) }}</span>
                    @endif
                    @if (!empty($item['badge']))
                        <span
                            class="ghl-tag {{ ($item['badge'] ?? '') === 'unread' ? 'ghl-tag-unread' : '' }}">{{ $item['badge'] }}</span>
                    @endif
                </span>
            </a>
        @empty
            <div class="ghl-inbox-empty" style="min-height: 12rem; padding: 2rem 1rem;">
                @if (($channel ?? '') === 'calls' || ($channel ?? '') === 'recordings')
                    @include('communications.inbox.partials.zoom-data-hint')
                @endif
                <p>No {{ strtolower($channelLabel) }} in this date range.</p>
                @if (!empty($warnings) && !in_array($channel ?? '', ['calls', 'recordings'], true))
                    <p class="text-xs text-amber-700 mt-3 max-w-xs mx-auto">{{ $warnings[0] }}</p>
                @endif
                @if (($channel ?? '') === 'calls' || ($channel ?? '') === 'recordings')
                    <p class="text-xs text-zinc-500 mt-2">Widen the date range in the toolbar if you expect older calls.
                    </p>
                @endif
            </div>
        @endforelse
    </div>

    @if ($nextPageToken ?? null)
        <div class="ghl-inbox-sidebar-footer">
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
                class="comm-hub-btn comm-hub-btn-secondary w-full text-center comm-hub-btn-sm">Load more</a>
        </div>
    @endif
</aside>
