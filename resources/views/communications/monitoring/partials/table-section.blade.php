@php
    $tone = $tone ?? 'blue';
    $bucket = $bucket ?? 'ringing';
    $rows = $rows ?? [];
    $empty = $empty ?? 'No calls.';
    $timerLabel = $timerLabel ?? 'Timer';
    $destinationLabel = $destinationLabel ?? 'Destination';
    $count = count($rows);
@endphp
<section class="call-monitoring-board call-monitoring-board--{{ $tone }}" data-call-monitoring-board="{{ $bucket }}">
    <div class="call-monitoring-board__indicator call-monitoring-board__indicator--{{ $tone }}" aria-hidden="true">
        <span class="call-monitoring-board__indicator-dot"></span>
        <span class="call-monitoring-board__indicator-label">{{ $title }}</span>
        <span class="call-monitoring-board__count" data-board-count>{{ $count }}</span>
    </div>
    <div class="call-monitoring-board__head">
        <h2 class="call-monitoring-board__title">{{ $title }}</h2>
        <span class="call-monitoring-board__count call-monitoring-board__count--head" data-board-count-head>{{ $count }}</span>
    </div>
    <div class="app-card call-monitoring-table-wrap">
        <div class="call-monitoring-table-scroll">
            <table class="call-monitoring-table">
                <thead>
                    <tr>
                        <th>Station</th>
                        <th>User</th>
                        <th>Status</th>
                        <th>{{ $timerLabel }}</th>
                        <th>{{ $destinationLabel }}</th>
                        <th>Campaign</th>
                    </tr>
                </thead>
                <tbody data-call-monitoring-rows="{{ $bucket }}">
                    @forelse ($rows as $row)
                        @include('communications.monitoring.partials.row', ['row' => $row])
                    @empty
                        <tr class="call-monitoring-empty" data-call-monitoring-empty>
                            <td colspan="6">{{ $empty }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
