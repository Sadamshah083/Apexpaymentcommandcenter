@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub')

@section('content')
    <div class="ghl-hub">
        @include('communications.partials.hub-tabs', ['mode' => 'contacts', 'routePrefix' => $routePrefix])

        @if ($error)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
        @endif

        <div class="ghl-split">
            <aside class="ghl-list-panel">
                <div class="ghl-list-toolbar">
                    <form method="GET" action="{{ route($routePrefix . 'communications.index') }}" class="ghl-search-form">
                        <input type="hidden" name="mode" value="contacts">
                        @if ($selectedKey)
                            <input type="hidden" name="contact" value="{{ $selectedKey }}">
                        @endif
                        <input type="search" name="search" value="{{ $filters['search'] ?? '' }}"
                            placeholder="Search name, phone, email" class="ghl-search-input">
                    </form>
                    <div class="ghl-filter-pills">
                        @foreach (['' => 'All', 'recent' => 'Recent', 'with_phone' => 'With phone'] as $value => $label)
                            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['contact', 'search']), ['mode' => 'contacts', 'filter' => $value ?: null])) }}"
                                class="ghl-pill {{ ($filters['filter'] ?? '') === $value ? 'ghl-pill-active' : '' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="ghl-list-count">{{ count($contacts) }} contacts</div>

                <div class="ghl-contact-list">
                    @forelse($contacts as $contact)
                        <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['search', 'filter']), ['mode' => 'contacts', 'contact' => $contact['contact_key']])) }}"
                            class="ghl-contact-row {{ ($selectedKey ?? '') === $contact['contact_key'] ? 'ghl-contact-row-active' : '' }}">
                            <span class="ghl-avatar">{{ strtoupper(substr($contact['name'], 0, 2)) }}</span>
                            <span class="ghl-contact-meta">
                                <span class="ghl-contact-name">{{ $contact['name'] }}</span>
                                <span class="ghl-contact-sub">
                                    {{ $contact['phone'] ?? ($contact['email'] ?? 'No phone or email') }}
                                </span>
                            </span>
                            <span class="ghl-contact-side">
                                @if ($contact['last_activity_at'])
                                    <span
                                        class="ghl-contact-time">{{ \Carbon\Carbon::parse($contact['last_activity_at'])->diffForHumans(short: true) }}</span>
                                @endif
                                <span class="ghl-tag">{{ $contact['tag'] ?? 'contact' }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="ghl-empty">No contacts in the last
                            {{ config('integrations.communications.default_days', 14) }} days.</div>
                    @endforelse
                </div>
            </aside>

            <main class="ghl-detail-panel">
                @if ($selectedContact)
                    @include('communications.contacts.partials.detail', [
                        'contact' => $selectedContact,
                        'timeline' => $timeline,
                        'stats' => $stats,
                        'routePrefix' => $routePrefix,
                        'smsSession' => $smsSession ?? null,
                    ])
                @else
                    <div class="ghl-detail-empty">
                        <div class="ghl-detail-empty-icon" aria-hidden="true">💬</div>
                        <h2 class="app-page-title text-lg">Select a contact</h2>
                        <p class="app-page-subtitle max-w-sm">Choose someone from the list to see their profile and call
                            history.</p>
                    </div>
                @endif
            </main>
        </div>
    </div>

    @include('communications.partials.audio-player')
@endsection

@push('scripts')
    @include('communications.partials.audio-player-script')
@endpush
