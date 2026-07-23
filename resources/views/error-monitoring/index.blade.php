@extends('layouts.admin')

@section('title', 'Error Monitoring')

@section('content')
    <div class="app-page">
        <div class="app-page-header">
            <div>
                <h1 class="app-page-title">Error Monitoring</h1>
                <p class="app-page-subtitle">Admin-only feed of application exceptions and failed queue jobs. Fixed errors are removed automatically when known.</p>
            </div>
            <div class="um-page-header-actions" style="display:flex;gap:0.5rem;flex-wrap:wrap">
                <form method="POST" action="{{ route('admin.error-monitoring.clear-resolved') }}">
                    @csrf
                    <button type="submit" class="um-btn um-btn-soft um-btn-sm">Clear resolved</button>
                </form>
                <form method="POST" action="{{ route('admin.error-monitoring.clear-all') }}"
                    onsubmit="return confirm('Clear all application errors from monitoring?');">
                    @csrf
                    <button type="submit" class="um-btn um-btn-soft um-btn-sm um-btn-danger-text">Clear all</button>
                </form>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 app-stat-grid mb-4">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Application errors</p>
                <p class="app-kpi-value">{{ number_format($errorCount) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Failed jobs</p>
                <p class="app-kpi-value">{{ number_format($failedCount) }}</p>
            </div>
        </div>

        <section class="app-card app-card-padded mb-4">
            <h2 class="app-section-title">Recent exceptions</h2>
            <div class="agent-status-table-wrap" style="margin-top:0.75rem">
                <table class="agent-status-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Where</th>
                            <th>User</th>
                            <th>Count</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($errors as $error)
                            <tr>
                                <td class="whitespace-nowrap">{{ optional($error->last_seen_at)->timezone(config('app.timezone'))->format('M j, g:i A') ?? '—' }}</td>
                                <td>{{ class_basename((string) $error->exception_class) }}</td>
                                <td>
                                    <div style="max-width:28rem;white-space:normal">{{ \Illuminate\Support\Str::limit($error->message, 180) }}</div>
                                    @if ($error->url)
                                        <div class="text-xs text-zinc-500" style="margin-top:0.25rem">{{ $error->method }} {{ \Illuminate\Support\Str::limit($error->url, 80) }}</div>
                                    @endif
                                </td>
                                <td class="text-xs">
                                    {{ $error->file ? basename((string) $error->file) : '—' }}
                                    @if ($error->line):{{ $error->line }}@endif
                                </td>
                                <td>{{ $error->user?->email ?: '—' }}</td>
                                <td class="tabular-nums">{{ number_format($error->occurrences) }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.error-monitoring.destroy', $error) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="um-btn um-btn-soft um-btn-sm um-btn-danger-text">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="agent-status-empty">No application errors recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="app-card app-card-padded">
            <h2 class="app-section-title">Failed queue jobs</h2>
            <div class="agent-status-table-wrap" style="margin-top:0.75rem">
                <table class="agent-status-table">
                    <thead>
                        <tr>
                            <th>Failed at</th>
                            <th>Queue</th>
                            <th>Exception</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($failedJobs as $job)
                            <tr>
                                <td class="whitespace-nowrap">{{ $job->failed_at ?? '—' }}</td>
                                <td>{{ $job->queue ?: ($job->connection ?: '—') }}</td>
                                <td>
                                    <div style="max-width:40rem;white-space:pre-wrap;font-size:0.75rem">{{ \Illuminate\Support\Str::limit((string) $job->exception, 400) }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="agent-status-empty">No failed jobs.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
