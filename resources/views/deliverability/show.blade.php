@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Deliverability Report')

@php
    $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.';
@endphp

@section('content')
    @php
        $score = $test->overall_score;
        $scoreClass =
            $score >= 7 ? 'deliverability-report-score--good' : ($score >= 4 ? 'deliverability-report-score--warn' : 'deliverability-report-score--bad');
    @endphp

    <div class="app-page deliverability-page deliverability-report space-y-5">
        <div class="app-page-header">
            <a href="{{ route($routePrefix . 'deliverability.index') }}" class="deliverability-back-link">&larr; Back to
                tests</a>
            <h1 class="app-page-title mt-2">{{ $test->domain }}</h1>
            <p class="app-page-subtitle">
                Deliverability score:
                <span class="deliverability-report-score {{ $scoreClass }}">{{ $score }}/10</span>
            </p>
        </div>

        @if ($test->status === 'pending' || $test->status === 'processing')
            <div id="deliverability-pending-panel" class="app-alert app-alert-warning">
                <p class="app-alert-title">Test is {{ $test->status }}…</p>
                <p class="app-alert-desc">Results update automatically when the scan completes.</p>
            </div>
        @endif

        @php
            $checks = [
                'SPF' => $test->spf_result,
                'DKIM' => $test->dkim_result,
                'DMARC' => $test->dmarc_result,
                'MX' => $test->mx_result,
                'PTR' => $test->ptr_result,
                'DNSBL' => $test->dnsbl_result,
            ];
        @endphp

        <div class="deliverability-check-grid grid gap-4 md:grid-cols-2">
            @foreach ($checks as $name => $result)
                @if ($result)
                    @php
                        $status = $result['status'] ?? 'unknown';
                        $statusBadge = match ($status) {
                            'pass' => 'app-badge app-badge-success',
                            'warn' => 'app-badge app-badge-warning',
                            'fail' => 'app-badge app-badge-danger',
                            default => 'app-badge app-badge-muted',
                        };
                    @endphp
                    <div class="app-card app-card-padded deliverability-check-card">
                        <div class="flex justify-between items-center gap-3 mb-2">
                            <h2 class="app-section-title mb-0">{{ $name }}</h2>
                            <span class="{{ $statusBadge }}">{{ $status }}</span>
                        </div>
                        <p class="deliverability-check-message">{{ $result['message'] ?? '' }}</p>
                        @if (isset($result['score']))
                            <p class="deliverability-check-meta">Score: {{ $result['score'] }}/10</p>
                        @endif
                        @if (!empty($result['record']))
                            <pre class="deliverability-check-record">{{ Str::limit($result['record'], 200) }}</pre>
                        @endif
                        @if (!empty($result['recommendation']))
                            <p class="deliverability-check-rec">{{ $result['recommendation'] }}</p>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>

        @if ($test->recommendations)
            <div class="app-card app-card-padded">
                <h2 class="app-section-title">Recommendations</h2>
                <ul class="deliverability-rec-list">
                    @foreach ($test->recommendations as $rec)
                        <li>{{ $rec }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    @if ($test->status === 'pending' || $test->status === 'processing')
        <script>
            (function() {
                const statusUrl = @json(route($routePrefix . 'deliverability.status', $test));
                const poll = () => {
                    fetch(statusUrl, {
                            headers: {
                                Accept: 'application/json'
                            },
                            credentials: 'same-origin'
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.complete) location.reload();
                        })
                        .catch(() => {});
                };
                poll();
                setInterval(poll, 5000);
            })();
        </script>
    @endif
@endpush
