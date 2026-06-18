<aside class="ghl-inbox-sidebar">
    <div class="ghl-inbox-sidebar-toolbar">
        @if($channel === 'inbox')
            <div class="ghl-filter-pills">
                @foreach(['' => 'All', 'recent' => 'Recent', 'with_phone' => 'With phone'] as $value => $label)
                    <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['filter' => $value ?: null])) }}"
                       class="ghl-pill {{ ($filters['filter'] ?? '') === $value ? 'ghl-pill-active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        @elseif($channel === 'calls')
            <div class="ghl-filter-pills">
                <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['filter' => null, 'direction' => null])) }}"
                   class="ghl-pill {{ ($filters['filter'] ?? '') === '' && ($filters['direction'] ?? '') === '' ? 'ghl-pill-active' : '' }}">All</a>
                <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['direction' => 'inbound', 'filter' => null])) }}"
                   class="ghl-pill {{ ($filters['direction'] ?? '') === 'inbound' ? 'ghl-pill-active' : '' }}">Inbound</a>
                <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['direction' => 'outbound', 'filter' => null])) }}"
                   class="ghl-pill {{ ($filters['direction'] ?? '') === 'outbound' ? 'ghl-pill-active' : '' }}">Outbound</a>
                <a href="{{ route($routePrefix.'communications.index', array_merge($baseQuery, ['filter' => 'missed', 'direction' => null])) }}"
                   class="ghl-pill {{ ($filters['filter'] ?? '') === 'missed' ? 'ghl-pill-active' : '' }}">Missed</a>
            </div>
        @elseif($channel === 'voicemail')
            <form method="GET" class="ghl-inbox-sidebar-filter">
                <input type="hidden" name="channel" value="voicemail">
                <select name="status" class="comm-hub-input comm-hub-input-sm w-full" onchange="this.form.submit()">
                    @foreach(['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $value => $label)
                        <option value="{{ $value }}" {{ ($filters['status'] ?? 'all') === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="ghl-list-count">{{ count($sidebarItems) }} {{ $channels[$channel]['label'] ?? 'items' }}</div>

    <div class="ghl-contact-list">
        @forelse($sidebarItems as $item)
            <a href="{{ $item['url'] }}"
               class="ghl-contact-row {{ !empty($item['active']) ? 'ghl-contact-row-active' : '' }}">
                <span class="ghl-avatar">{{ $item['avatar'] }}</span>
                <span class="ghl-contact-meta">
                    <span class="ghl-contact-name">{{ $item['label'] }}</span>
                    <span class="ghl-contact-sub">{{ $item['subtitle'] }}</span>
                </span>
                <span class="ghl-contact-side">
                    @if(!empty($item['time']))
                        <span class="ghl-contact-time">{{ \Carbon\Carbon::parse($item['time'])->diffForHumans(short: true) }}</span>
                    @endif
                    @if(!empty($item['badge']))
                        <span class="ghl-tag {{ ($item['badge'] ?? '') === 'unread' ? 'ghl-tag-unread' : '' }}">{{ $item['badge'] }}</span>
                    @endif
                </span>
            </a>
        @empty
            <div class="ghl-empty">Nothing here for this channel yet.</div>
        @endforelse
    </div>

    @if($nextPageToken ?? null)
        <div class="ghl-inbox-sidebar-footer">
            <a href="{{ route($routePrefix.'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
               class="comm-hub-btn comm-hub-btn-secondary w-full text-center">Load more</a>
        </div>
    @endif
</aside>
