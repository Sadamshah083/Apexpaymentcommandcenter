@php
    $routePrefix = $routePrefix ?? 'admin.';
@endphp

<div class="app-page agent-status-page">
    <div class="agent-status-toolbar app-card">
        <div class="agent-status-toolbar__intro">
            <p class="agent-status-toolbar__eyebrow">Team Lead</p>
            <p class="agent-status-toolbar__title">Agent status</p>
        </div>
        <nav class="agent-status-toolbar__nav" aria-label="Agent status pages">
            <a href="{{ $monitoringUrl }}" class="agent-status-toolbar__link">Live Monitoring</a>
            <a href="{{ $formUrl }}" class="agent-status-toolbar__link is-active" aria-current="page">Talk Time &amp; Status</a>
        </nav>
        <div class="agent-status-toolbar__stats">
            <div class="agent-status-toolbar__stat">
                <span>Calls</span>
                <strong>{{ number_format($totalCalls) }}</strong>
            </div>
            <div class="agent-status-toolbar__stat">
                <span>Talk</span>
                <strong>{{ $totalDurationLabel }}</strong>
            </div>
        </div>
        <div class="agent-status-toolbar__actions">
            <a href="{{ $exportUrl }}" class="app-btn app-btn-success app-btn-sm">Export all status</a>
            <a href="{{ $exportLogsUrl }}" class="app-btn app-btn-secondary app-btn-sm">Export call logs</a>
        </div>
    </div>

    <div class="agent-status-main">
        <div class="app-page-header agent-status-header">
            <div>
                <h1 class="app-page-title">Call Log Report</h1>
                <p class="app-page-subtitle">Filter by date range and agent, then export status totals or full call logs.</p>
            </div>
        </div>

        <form method="get" action="{{ $formUrl }}" class="agent-status-filters app-card app-card-padded">
            <div class="agent-status-filters__grid">
                <label class="agent-status-field">
                    <span class="agent-status-field__label">From</span>
                    <input type="date" name="from" value="{{ $from }}" class="app-input" required>
                </label>
                <label class="agent-status-field">
                    <span class="agent-status-field__label">To</span>
                    <input type="date" name="to" value="{{ $to }}" class="app-input" required>
                </label>
                <label class="agent-status-field">
                    <span class="agent-status-field__label">User</span>
                    <select name="user_id" class="app-input">
                        <option value="0">All users</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent['id'] }}" @selected((int) $selectedAgentId === (int) $agent['id'])>
                                {{ $agent['name'] }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <div class="agent-status-filters__submit">
                    <button type="submit" class="app-btn app-btn-success app-btn-sm">Apply range</button>
                </div>
            </div>
        </form>

        <div class="agent-status-panels">
            <section class="app-card app-card-padded agent-status-talk">
                <div class="agent-status-section-head">
                    <h2 class="app-section-title">Agent Talk Time and Status</h2>
                    <a href="{{ $exportUrl }}" class="app-link text-sm font-semibold">Download CSV</a>
                </div>
                <div class="agent-status-table-wrap">
                    <table class="agent-status-table">
                        <thead>
                            <tr>
                                <th scope="col">Status</th>
                                <th scope="col" class="text-right">Count</th>
                                <th scope="col" class="text-right">Hours:MM:SS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($statusRows as $row)
                                <tr>
                                    <td>{{ $row['status'] }}</td>
                                    <td class="text-right">{{ number_format($row['count']) }}</td>
                                    <td class="text-right tabular-nums">{{ $row['duration_label'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="agent-status-empty">No dispositions in this date range.</td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($statusRows !== [])
                            <tfoot>
                                <tr>
                                    <th scope="row">TOTAL CALLS</th>
                                    <td class="text-right">{{ number_format($totalCalls) }}</td>
                                    <td class="text-right tabular-nums">{{ $totalDurationLabel }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </section>

            <section class="app-card app-card-padded agent-status-logs">
                <div class="agent-status-section-head">
                    <h2 class="app-section-title">Call results</h2>
                    <span class="agent-status-muted">{{ number_format(count($callLogs)) }} shown</span>
                </div>
                <div class="agent-status-table-wrap agent-status-table-wrap--scroll">
                    <table class="agent-status-table agent-status-table--logs">
                        <thead>
                            <tr>
                                <th scope="col">Agent</th>
                                <th scope="col">When</th>
                                <th scope="col">Status</th>
                                <th scope="col">Duration</th>
                                <th scope="col">Phone</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($callLogs as $log)
                                <tr>
                                    <td>{{ $log['agent'] }}</td>
                                    <td class="whitespace-nowrap">{{ $log['when'] }}</td>
                                    <td><span class="agent-status-pill">{{ $log['status'] }}</span></td>
                                    <td class="tabular-nums">{{ $log['duration_label'] }}</td>
                                    <td class="tabular-nums">{{ $log['phone'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="agent-status-empty">No call logs in this date range.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
