@php
    $summary = $snapshot['summary'] ?? [];
    $tables = $snapshot['tables'] ?? [
        'ringing' => [],
        'incall_short' => [],
        'incall_long' => [],
        'not_in_call' => [],
        'queue' => [],
        'dead' => [],
    ];
    if (($tables['ringing'] ?? []) === [] && ($tables['incall_short'] ?? []) === [] && ($tables['incall_long'] ?? []) === [] && ($tables['not_in_call'] ?? []) === []) {
        $rows = collect($snapshot['rows'] ?? []);
        $tables = [
            'ringing' => $rows->where('bucket', 'ringing')->values()->all(),
            'incall_short' => $rows->where('bucket', 'incall_short')->values()->all(),
            'incall_long' => $rows->where('bucket', 'incall_long')->values()->all(),
            'not_in_call' => $rows->where('bucket', 'not_in_call')->values()->all(),
            'queue' => $rows->where('bucket', 'queue')->values()->all(),
            'dead' => $rows->where('bucket', 'dead')->values()->all(),
        ];
    }
    $warnings = $snapshot['warnings'] ?? [];
    $generatedAt = $snapshot['generated_at'] ?? now()->toIso8601String();
@endphp

<div class="app-page call-monitoring-page"
    data-call-monitoring
    data-call-monitoring-poll-url="{{ $pollUrl }}"
    data-call-monitoring-stream-url="{{ $streamUrl ?? '' }}">
    <div class="app-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="app-page-title">Call Monitoring</h1>
            <p class="app-page-subtitle">
                Live calls plus logged-in agents not in a call. Dial mode (Auto / Manual) shown per agent. Timer starts for connected calls when both sides answer.
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
        <div class="call-monitoring-stat">
            <span class="call-monitoring-stat__label">In call total</span>
            <span class="call-monitoring-stat__value" data-stat="in_call">{{ (int) ($summary['in_call'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat call-monitoring-stat--idle">
            <span class="call-monitoring-stat__label">Not in call</span>
            <span class="call-monitoring-stat__value" data-stat="not_in_call">{{ (int) ($summary['not_in_call'] ?? 0) }}</span>
        </div>
        <div class="call-monitoring-stat">
            <span class="call-monitoring-stat__label">Agents shown</span>
            <span class="call-monitoring-stat__value" data-stat="total">{{ (int) ($summary['total'] ?? 0) }}</span>
        </div>
    </div>

    <div class="call-monitoring-boards">
        @include('communications.monitoring.partials.table-section', [
            'title' => 'Ringing',
            'tone' => 'blue',
            'bucket' => 'ringing',
            'rows' => $tables['ringing'] ?? [],
            'empty' => 'No ringing calls.',
        ])
        @include('communications.monitoring.partials.table-section', [
            'title' => 'In call ≤ 2 minutes',
            'tone' => 'pink',
            'bucket' => 'incall_short',
            'rows' => $tables['incall_short'] ?? [],
            'empty' => 'No connected calls under 2 minutes.',
        ])
        @include('communications.monitoring.partials.table-section', [
            'title' => 'In call > 2 minutes',
            'tone' => 'pink-long',
            'bucket' => 'incall_long',
            'rows' => $tables['incall_long'] ?? [],
            'empty' => 'No connected calls over 2 minutes.',
        ])
        @include('communications.monitoring.partials.table-section', [
            'title' => 'Not in call (logged in)',
            'tone' => 'idle',
            'bucket' => 'not_in_call',
            'rows' => $tables['not_in_call'] ?? [],
            'empty' => 'No logged-in agents available (not in call).',
            'timerLabel' => 'Idle',
            'destinationLabel' => 'Dial mode',
        ])
    </div>
</div>
