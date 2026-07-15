@php
    $summary = $snapshot['summary'] ?? [];
    $tables = $snapshot['tables'] ?? [
        'ringing' => [],
        'incall_short' => [],
        'incall_long' => [],
        'disposition' => [],
        'not_in_call' => [],
        'not_logged_in' => [],
        'queue' => [],
        'dead' => [],
    ];
    if (($tables['ringing'] ?? []) === [] && ($tables['incall_short'] ?? []) === [] && ($tables['incall_long'] ?? []) === [] && ($tables['not_in_call'] ?? []) === [] && ($tables['not_logged_in'] ?? []) === [] && ($tables['dead'] ?? []) === [] && ($tables['disposition'] ?? []) === []) {
        $rows = collect($snapshot['rows'] ?? []);
        $tables = [
            'ringing' => $rows->where('bucket', 'ringing')->values()->all(),
            'incall_short' => $rows->where('bucket', 'incall_short')->values()->all(),
            'incall_long' => $rows->where('bucket', 'incall_long')->values()->all(),
            'disposition' => $rows->where('bucket', 'disposition')->values()->all(),
            'not_in_call' => $rows->where('bucket', 'not_in_call')->values()->all(),
            'not_logged_in' => $rows->where('bucket', 'not_logged_in')->values()->all(),
            'queue' => $rows->where('bucket', 'queue')->values()->all(),
            'dead' => $rows->where('bucket', 'dead')->values()->all(),
        ];
        if (($tables['not_logged_in'] ?? []) === [] && is_array($snapshot['not_logged_in'] ?? null)) {
            $tables['not_logged_in'] = $snapshot['not_logged_in'];
        }
    }

    // Display order: Not in call → Ringing → In ≤2 → In >2 → Disposition → Not logged in
    $bucketRank = [
        'not_in_call' => 0,
        'ringing' => 1,
        'waiting' => 1,
        'queue' => 1,
        'incall_short' => 2,
        'incall_long' => 3,
        'disposition' => 4,
        'not_logged_in' => 5,
        'dead' => 6,
    ];
    $unifiedRows = collect([
        ...($tables['not_in_call'] ?? []),
        ...($tables['ringing'] ?? []),
        ...($tables['queue'] ?? []),
        ...($tables['incall_short'] ?? []),
        ...($tables['incall_long'] ?? []),
        ...($tables['disposition'] ?? []),
        ...($tables['not_logged_in'] ?? []),
        ...($tables['dead'] ?? []),
    ])->sortBy(function (array $row) use ($bucketRank) {
        $bucket = (string) ($row['bucket'] ?? 'ringing');
        $rank = $bucketRank[$bucket] ?? 9;
        $name = strtolower((string) ($row['user'] ?? ''));
        $id = (string) ($row['id'] ?? '');

        return sprintf('%02d-%s-%s', $rank, $name, $id);
    })->values()->all();

    $warnings = $snapshot['warnings'] ?? [];
    $generatedAt = $snapshot['generated_at'] ?? now()->toIso8601String();
    $totalShown = count($unifiedRows);
@endphp

<div class="app-page call-monitoring-page"
    data-call-monitoring
    data-call-monitoring-unified="1"
    data-call-monitoring-poll-url="{{ $pollUrl }}"
    data-call-monitoring-stream-url="{{ $streamUrl ?? '' }}">
    <div class="app-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="app-page-title">Call Monitoring</h1>
            <p class="app-page-subtitle">
                Live board for idle, ringing, connected, disposition, and offline agents.
            </p>
        </div>
        <div class="call-monitoring-live-badge" aria-live="polite">
            <span class="call-monitoring-live-dot" aria-hidden="true"></span>
            <span data-call-monitoring-clock>Live</span>
            <span class="call-monitoring-live-sep" aria-hidden="true">·</span>
            <span data-call-monitoring-updated>{{ \Carbon\Carbon::parse($generatedAt)->format('H:i:s') }}</span>
        </div>
    </div>

    @if ($warnings !== [])
        <div class="call-monitoring-warnings">
            @foreach ($warnings as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    <div class="call-monitoring-summary" data-call-monitoring-summary>
        <div class="call-monitoring-stat call-monitoring-stat--idle">
            <span class="call-monitoring-stat__label">Not in call</span>
            <span class="call-monitoring-stat__value" data-stat="not_in_call">{{ (int) ($summary['not_in_call'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--blue">
            <span class="call-monitoring-stat__label">Ringing</span>
            <span class="call-monitoring-stat__value" data-stat="ringing">{{ (int) ($summary['ringing'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--pink">
            <span class="call-monitoring-stat__label">In call ≤ 2 min</span>
            <span class="call-monitoring-stat__value" data-stat="in_call_short">{{ (int) ($summary['in_call_short'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--pink-long">
            <span class="call-monitoring-stat__label">In call &gt; 2 min</span>
            <span class="call-monitoring-stat__value" data-stat="in_call_long">{{ (int) ($summary['in_call_long'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--disposition">
            <span class="call-monitoring-stat__label">Disposition</span>
            <span class="call-monitoring-stat__value" data-stat="disposition">{{ (int) ($summary['disposition'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--logged-in">
            <span class="call-monitoring-stat__label">Logged in</span>
            <span class="call-monitoring-stat__value" data-stat="logged_in">{{ (int) ($summary['logged_in'] ?? $summary['agents_online'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--offline">
            <span class="call-monitoring-stat__label">Not logged in</span>
            <span class="call-monitoring-stat__value" data-stat="not_logged_in">{{ (int) ($summary['not_logged_in'] ?? 0) }}</span>
        </div>
    </div>

    <section class="call-monitoring-board call-monitoring-board--unified" data-call-monitoring-board="all">
        <div class="call-monitoring-legend call-monitoring-legend--inline" aria-label="Status colors">
            <span class="call-monitoring-legend__item call-monitoring-legend__item--idle">Not in call</span>
            <span class="call-monitoring-legend__item call-monitoring-legend__item--blue">Ringing</span>
            <span class="call-monitoring-legend__item call-monitoring-legend__item--pink">In call ≤2m</span>
            <span class="call-monitoring-legend__item call-monitoring-legend__item--pink-long">In call &gt;2m</span>
            <span class="call-monitoring-legend__item call-monitoring-legend__item--disposition">Disposition</span>
            <span class="call-monitoring-legend__item call-monitoring-legend__item--offline">Not logged in</span>
        </div>
        <div class="app-card call-monitoring-table-wrap">
            <div class="call-monitoring-table-scroll">
                <table class="call-monitoring-table call-monitoring-table--unified">
                    <thead>
                        <tr>
                            <th>Station</th>
                            <th>User</th>
                            <th>Status</th>
                            <th>Timer</th>
                            <th>Destination / Mode</th>
                            <th>Campaign</th>
                        </tr>
                    </thead>
                    <tbody data-call-monitoring-rows="all">
                        @forelse ($unifiedRows as $row)
                            @include('communications.monitoring.partials.row', ['row' => $row])
                        @empty
                            <tr class="call-monitoring-empty" data-call-monitoring-empty>
                                <td colspan="6">No agents or calls to show right now.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
