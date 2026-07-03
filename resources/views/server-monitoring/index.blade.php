@extends('layouts.admin')

@section('title', 'Server Monitoring')

@section('content')
    @php
        $cpuBarClass = $cpu['percentage'] > 80 ? 'is-danger' : ($cpu['percentage'] > 50 ? 'is-warn' : 'is-good');
        $ramBarClass = $ram['percentage'] > 85 ? 'is-danger' : ($ram['percentage'] > 60 ? 'is-warn' : 'is-good');
        $diskBarClass = $disk['percentage'] > 90 ? 'is-danger' : ($disk['percentage'] > 75 ? 'is-warn' : 'is-good');
        $dbConnected = str_starts_with($dbStatus, 'Connected');
    @endphp

    <div class="app-page server-monitoring-page">
        <div class="app-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="app-page-title">Server Monitoring</h1>
                <p class="app-page-subtitle">Real-time resource utilization, database status, and background queues.</p>
            </div>
            <div class="server-monitoring-status">
                <span class="server-monitoring-status-dot" aria-hidden="true"></span>
                System Online
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-3 app-stat-grid server-monitoring-gauges">
            <div class="app-card app-card-padded server-monitoring-gauge">
                <div class="server-monitoring-gauge-head">
                    <p class="app-kpi-label">CPU Usage</p>
                    <span class="server-monitoring-meta">{{ $cpu['cores'] }} Cores</span>
                </div>
                <div class="server-monitoring-gauge-value">
                    <span class="app-kpi-value">{{ $cpu['percentage'] }}%</span>
                    <span class="server-monitoring-hint">utilization</span>
                </div>
                <div class="server-monitoring-bar">
                    <div class="server-monitoring-bar-fill {{ $cpuBarClass }}"
                        style="width: {{ min(100, max(0, $cpu['percentage'])) }}%"></div>
                </div>
            </div>

            <div class="app-card app-card-padded server-monitoring-gauge">
                <div class="server-monitoring-gauge-head">
                    <p class="app-kpi-label">Memory Usage</p>
                    <span class="server-monitoring-meta">{{ $ram['used'] }} / {{ $ram['total'] }}</span>
                </div>
                <div class="server-monitoring-gauge-value">
                    <span class="app-kpi-value">{{ $ram['percentage'] }}%</span>
                    <span class="server-monitoring-hint">allocated</span>
                </div>
                <div class="server-monitoring-bar">
                    <div class="server-monitoring-bar-fill {{ $ramBarClass }}"
                        style="width: {{ min(100, max(0, $ram['percentage'])) }}%"></div>
                </div>
            </div>

            <div class="app-card app-card-padded server-monitoring-gauge">
                <div class="server-monitoring-gauge-head">
                    <p class="app-kpi-label">Disk Space</p>
                    <span class="server-monitoring-meta">{{ $disk['used'] }} used of {{ $disk['total'] }}</span>
                </div>
                <div class="server-monitoring-gauge-value">
                    <span class="app-kpi-value">{{ $disk['percentage'] }}%</span>
                    <span class="server-monitoring-hint">occupied</span>
                </div>
                <div class="server-monitoring-bar">
                    <div class="server-monitoring-bar-fill {{ $diskBarClass }}"
                        style="width: {{ min(100, max(0, $disk['percentage'])) }}%"></div>
                </div>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 server-monitoring-details">
            <div class="app-card app-card-padded server-monitoring-panel">
                <h2 class="app-section-title">System Specifications</h2>
                <dl class="server-monitoring-specs">
                    <div class="server-monitoring-spec-row">
                        <dt>Operating System</dt>
                        <dd>{{ $os }}</dd>
                    </div>
                    <div class="server-monitoring-spec-row">
                        <dt>PHP Version</dt>
                        <dd>{{ $phpVersion }}</dd>
                    </div>
                    <div class="server-monitoring-spec-row">
                        <dt>Laravel Version</dt>
                        <dd>v{{ $laravelVersion }}</dd>
                    </div>
                    <div class="server-monitoring-spec-row">
                        <dt>Database Status</dt>
                        <dd>
                            <span class="server-monitoring-db-status {{ $dbConnected ? 'is-connected' : 'is-disconnected' }}">
                                <span class="server-monitoring-db-dot" aria-hidden="true"></span>
                                {{ $dbStatus }}
                            </span>
                        </dd>
                    </div>
                    <div class="server-monitoring-spec-row">
                        <dt>Database Name</dt>
                        <dd>{{ $dbName }}</dd>
                    </div>
                    <div class="server-monitoring-spec-row">
                        <dt>System Uptime</dt>
                        <dd>{{ $uptime }}</dd>
                    </div>
                </dl>
            </div>

            <div class="app-card app-card-padded server-monitoring-panel server-monitoring-queue-panel">
                <h2 class="app-section-title">Background Worker &amp; Queues</h2>
                <div class="grid grid-cols-2 gap-3 server-monitoring-queue-stats">
                    <div class="server-monitoring-queue-stat">
                        <p class="app-kpi-label">Active Queue Jobs</p>
                        <p class="app-kpi-value">{{ $queueActive }}</p>
                    </div>
                    <div class="server-monitoring-queue-stat server-monitoring-queue-stat--failed">
                        <p class="app-kpi-label">Failed Jobs</p>
                        <p class="app-kpi-value">{{ $queueFailed }}</p>
                    </div>
                </div>
                <div class="server-monitoring-queue-note">
                    <p class="server-monitoring-queue-note-title">Queue Connection Details</p>
                    <p>Queue workers check for jobs in the <strong>{{ config('queue.default') }}</strong> connection.
                        Active processes are managed by the supervisor daemon when deployed.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
