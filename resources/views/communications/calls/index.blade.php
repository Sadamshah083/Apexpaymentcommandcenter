@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Communications Hub — Calls')

@section('content')
    @php
        $morpheusQueues = $morpheusQueues ?? [];
        $morpheusUsers = $morpheusUsers ?? [];
        $morpheusConferences = $morpheusConferences ?? [];
    @endphp
    <div class="ghl-hub">
        @include('communications.partials.hub-tabs', ['mode' => 'calls', 'routePrefix' => $routePrefix])

        @foreach ($warnings ?? [] as $warning)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $warning }}</div>
        @endforeach

        @if ($error)
            <div class="comm-hub-alert comm-hub-alert-warning">{{ $error }}</div>
        @endif

        @if (!empty($stats))
            <div class="ghl-stat-row mb-4">
                <div class="ghl-stat-chip">
                    <span class="ghl-stat-chip-value">{{ $stats['total'] ?? 0 }}</span>
                    <span class="ghl-stat-chip-label">Total calls</span>
                </div>
                <div class="ghl-stat-chip">
                    <span class="ghl-stat-chip-value">{{ $stats['inbound'] ?? 0 }}</span>
                    <span class="ghl-stat-chip-label">Inbound</span>
                </div>
                <div class="ghl-stat-chip">
                    <span class="ghl-stat-chip-value">{{ $stats['outbound'] ?? 0 }}</span>
                    <span class="ghl-stat-chip-label">Outbound</span>
                </div>
                <div class="ghl-stat-chip">
                    <span class="ghl-stat-chip-value">{{ $stats['recorded'] ?? 0 }}</span>
                    <span class="ghl-stat-chip-label">Recorded</span>
                </div>
                <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls', 'filter' => 'missed'])) }}"
                    class="ghl-stat-chip ghl-stat-chip-link {{ ($filters['filter'] ?? '') === 'missed' ? 'ghl-stat-chip-active' : '' }}">
                    <span class="ghl-stat-chip-value">{{ $stats['missed'] ?? 0 }}</span>
                    <span class="ghl-stat-chip-label">Missed</span>
                </a>
                <div class="ghl-stat-chip">
                    <span class="ghl-stat-chip-value">{{ (int) floor(($stats['total_duration'] ?? 0) / 60) }}m</span>
                    <span class="ghl-stat-chip-label">Talk time</span>
                </div>
            </div>
        @endif

        <div class="ghl-calls-toolbar">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Call history</h2>
                <p class="text-sm text-slate-500">{{ count($callLogs) }} call{{ count($callLogs) === 1 ? '' : 's' }} on this page
                    from Morpheus CX</p>
            </div>
            <form method="GET" class="flex flex-wrap gap-2 items-end">
                <input type="hidden" name="mode" value="calls">
                <div>
                    <label class="comm-hub-label block mb-1">From</label>
                    <input type="date" name="from" value="{{ $filters['from'] }}" class="comm-hub-input">
                </div>
                <div>
                    <label class="comm-hub-label block mb-1">To</label>
                    <input type="date" name="to" value="{{ $filters['to'] }}" class="comm-hub-input">
                </div>
                <button type="submit" class="comm-hub-btn">Apply</button>
                <a href="{{ route($routePrefix . 'communications.zoom.export.logs', ['from' => $filters['from'], 'to' => $filters['to']]) }}"
                    class="comm-hub-btn comm-hub-btn-secondary">Export</a>
            </form>
        </div>

        <div class="ghl-filter-pills mb-4">
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls'])) }}"
                class="ghl-pill {{ ($filters['filter'] ?? '') !== 'recorded' && ($filters['filter'] ?? '') !== 'missed' && ($filters['direction'] ?? '') === '' ? 'ghl-pill-active' : '' }}">All
                calls</a>
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls', 'direction' => 'inbound'])) }}"
                class="ghl-pill {{ ($filters['direction'] ?? '') === 'inbound' ? 'ghl-pill-active' : '' }}">Inbound</a>
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls', 'direction' => 'outbound'])) }}"
                class="ghl-pill {{ ($filters['direction'] ?? '') === 'outbound' ? 'ghl-pill-active' : '' }}">Outbound</a>
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls', 'filter' => 'recorded'])) }}"
                class="ghl-pill {{ ($filters['filter'] ?? '') === 'recorded' ? 'ghl-pill-active' : '' }}">With
                recording</a>
            <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->only(['from', 'to']), ['mode' => 'calls', 'filter' => 'missed'])) }}"
                class="ghl-pill {{ ($filters['filter'] ?? '') === 'missed' ? 'ghl-pill-active' : '' }}">Missed</a>
        </div>

        <div class="ghl-call-feed">
            @forelse($callLogs as $log)
                <article class="ghl-call-card">
                    <div class="ghl-call-card-main">
                        <span class="ghl-avatar">{{ strtoupper(substr($log['direction'] ?? 'C', 0, 1)) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-slate-900">{{ $log['from'] }}</span>
                                <span class="text-slate-400">→</span>
                                <span class="font-semibold text-slate-900">{{ $log['to'] }}</span>
                                <span class="ghl-tag">{{ $log['direction'] }}</span>
                            </div>
                            <p class="text-sm text-slate-500 mt-1">
                                {{ $log['start_time'] ? \Carbon\Carbon::parse($log['start_time'])->format('M j, Y g:i A') : '—' }}
                                · {{ $log['result'] }} · {{ $log['duration'] }}s
                                @if (($log['recording'] ?? '') === 'Yes' || !empty($log['has_recording_media']))
                                    · <span class="text-emerald-600 font-medium">Recorded</span>
                                @endif
                            </p>
                        </div>
                    </div>
                    <div class="ghl-call-card-actions">
                        @if (($log['result'] ?? '') === 'Active Call')
                            @include('communications.partials.morpheus-call-controls', [
                                'routePrefix' => $routePrefix,
                                'uuid' => $log['id'],
                                'log' => $log,
                                'morpheusQueues' => $morpheusQueues ?? [],
                                'morpheusUsers' => $morpheusUsers ?? [],
                                'morpheusConferences' => $morpheusConferences ?? [],
                            ])
                        @else
                            @php
                                $callbackPhone = match ($log['direction'] ?? '') {
                                    'inbound' => $log['from_phone'] ?? null,
                                    'outbound' => $log['to_phone'] ?? null,
                                    default => $log['to_phone'] ?? ($log['from_phone'] ?? null),
                                };
                            @endphp
                            @if ($callbackPhone)
                                @include('communications.partials.call-actions', [
                                    'routePrefix' => $routePrefix,
                                    'phone' => $callbackPhone,
                                ])
                            @endif
                            @if (!empty($log['has_recording_media']) && !empty($log['recording_id']))
                                @include('communications.partials.recording-actions', [
                                    'routePrefix' => $routePrefix,
                                    'recordingId' => $log['recording_id'],
                                    'callReferenceId' => $log['call_reference_id'] ?? $log['recording_id'],
                                    'source' => $log['recording_source'] ?? 'morpheus',
                                    'hasMedia' => true,
                                ])
                            @elseif (($log['recording'] ?? '') === 'Yes')
                                <span class="text-xs text-emerald-600 font-medium">Recording on file</span>
                            @else
                                <span class="text-xs text-slate-400">No recording</span>
                            @endif
                        @endif
                    </div>
                </article>
            @empty
                <div class="ghl-empty py-16">No calls found for this date range.</div>
            @endforelse
        </div>

        @if (($prevPageToken ?? null) || ($nextPageToken ?? null))
            <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
                @if ($prevPageToken ?? null)
                    <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->query(), ['page_token' => $prevPageToken])) }}"
                        class="comm-hub-btn comm-hub-btn-secondary">← Previous</a>
                @endif
                <span class="text-sm text-slate-500">Page {{ $currentPage ?? 1 }}</span>
                @if ($nextPageToken ?? null)
                    <a href="{{ route($routePrefix . 'communications.index', array_merge(request()->query(), ['page_token' => $nextPageToken])) }}"
                        class="comm-hub-btn comm-hub-btn-secondary">Next →</a>
                @endif
            </div>
        @endif
    </div>

    @include('communications.partials.audio-player')
@endsection

@push('scripts')
    @include('communications.partials.audio-player-script')
@endpush
