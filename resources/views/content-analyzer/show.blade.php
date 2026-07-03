@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Content Report')

@php
    $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.';
    $mailTesterScore = $result['mail_tester_score'] ?? 0;
    $mailTesterClass =
        $mailTesterScore >= 7
            ? 'content-analyzer-report-score--good'
            : ($mailTesterScore >= 4
                ? 'content-analyzer-report-score--warn'
                : 'content-analyzer-report-score--bad');
@endphp

@section('content')
    <div class="app-page content-analyzer-page content-analyzer-report space-y-5">
        <div class="app-page-header">
            <a href="{{ route($routePrefix . 'content.index') }}" class="content-analyzer-back-link">&larr; Back to
                analyses</a>
            <h1 class="app-page-title mt-2">{{ $analysis->title ?? 'Content Analysis' }}</h1>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="app-card app-card-padded content-analyzer-stat text-center">
                <p class="content-analyzer-stat-label">Mail-Tester Style Score</p>
                <p class="content-analyzer-report-score {{ $mailTesterClass }}">{{ $mailTesterScore }}/10</p>
                <p class="content-analyzer-stat-hint">Higher is better</p>
            </div>
            <div class="app-card app-card-padded content-analyzer-stat text-center">
                <p class="content-analyzer-stat-label">Spam Risk Score</p>
                <p class="content-analyzer-report-score content-analyzer-report-score--bad">{{ $result['spam_score'] ?? 0 }}</p>
                <p class="content-analyzer-stat-hint">Lower is better (threshold: 5.0)</p>
            </div>
            <div class="app-card app-card-padded content-analyzer-stat text-center">
                <p class="content-analyzer-stat-label">Risk Level</p>
                <p class="content-analyzer-risk-level">{{ strtoupper($result['risk_level'] ?? 'unknown') }}</p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="app-card app-card-padded">
                <h2 class="app-section-title">Category Breakdown</h2>
                <div class="content-analyzer-breakdown">
                    @foreach ($result['scores'] ?? [] as $category => $score)
                        @if ($score != 0)
                            <div class="content-analyzer-breakdown-row">
                                <span class="capitalize">{{ str_replace('_', ' ', $category) }}</span>
                                <span class="{{ $score > 0 ? 'is-negative' : 'is-positive' }}">
                                    {{ $score > 0 ? '+' : '' }}{{ $score }}
                                </span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="app-card app-card-padded">
                <h2 class="app-section-title">Suggestions</h2>
                <div class="content-analyzer-suggestions">
                    @forelse($result['suggestions'] ?? [] as $suggestion)
                        <p>{{ $suggestion }}</p>
                    @empty
                        <p class="content-analyzer-suggestions-ok">No major issues detected.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="app-card app-card-padded">
            <h2 class="app-section-title">Subject (highlighted)</h2>
            <p class="content-analyzer-highlight">{!! $highlightedSubject !!}</p>
        </div>

        <div class="app-card app-card-padded">
            <h2 class="app-section-title">Body (highlighted)</h2>
            <div class="content-analyzer-highlight content-analyzer-body">{!! $highlightedBody !!}</div>
        </div>
    </div>
@endsection
