@extends('layouts.admin')

@section('title', 'Research Report')

@section('content')
    <div class="app-page business-research-page business-research-report space-y-5">
        <div class="app-page-header">
            <a href="{{ route('admin.business-research.index') }}" class="business-research-back-link">&larr; All
                research</a>
            <h1 class="app-page-title mt-2">{{ $research->business_name }}</h1>
            @if ($research->address)
                <p class="app-page-subtitle">{{ $research->address }}</p>
            @endif
        </div>

        @if (!$research->isComplete())
            <div id="loading-panel" class="app-alert app-alert-warning">
                <p class="app-alert-title">Research in progress…</p>
                <p class="app-alert-desc">Multi-source web research: DuckDuckGo directories + Gemini 2.5 Pro with Google
                    Search grounding. This may take 2–4 minutes.</p>
                <div class="app-progress-track mt-3">
                    <div class="app-progress-fill animate-pulse" style="width: 40%"></div>
                </div>
            </div>
        @endif

        @if ($research->status === 'failed')
            <div class="app-alert app-alert-danger">
                <p class="app-alert-title">Research failed</p>
                <p class="app-alert-desc">{{ $research->error_message }}</p>
                @if (str_contains($research->error_message ?? '', 'OpenRouter'))
                    <p class="app-alert-desc mt-2">This is an old error from before Gemini was enabled. Click Retry —
                        research now uses Gemini only.</p>
                @endif
                @if (str_contains($research->error_message ?? '', 'Gemini'))
                    <p class="app-alert-desc mt-2">Confirm <code class="business-research-code">GEMINI_API_KEY</code> in
                        <code class="business-research-code">.env</code> is from
                        <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"
                            class="business-research-inline-link">Google AI Studio</a>.
                    </p>
                @endif
                <form action="{{ route('admin.business-research.retry', $research) }}" method="POST" class="mt-3">
                    @csrf
                    <button type="submit" class="app-btn app-btn-primary">Retry</button>
                </form>
            </div>
        @endif

        @if ($research->status === 'completed')
            @php $data = $research->structured_data ?? []; @endphp

            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4 app-stat-grid">
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">Direct owner</p>
                    <p class="business-research-kpi-value">{{ $research->owner_name ?? 'Not Publicly Available' }}</p>
                </div>
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">Payment processor</p>
                    <p class="business-research-kpi-value">{{ $research->payment_processor ?? 'Not Publicly Available' }}</p>
                </div>
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">Phone</p>
                    <p class="business-research-kpi-value">
                        {{ $data['direct_phone'] ?? ($research->phones[0] ?? 'Not Publicly Available') }}</p>
                </div>
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">Email</p>
                    <p class="business-research-kpi-value business-research-kpi-value--break">
                        {{ $data['direct_email'] ?? ($research->emails[0] ?? 'Not Publicly Available') }}</p>
                </div>
            </div>

            @if ($research->raw_response)
                <div class="app-card app-card-padded business-research-report-body">
                    <h2 class="app-section-title">Full intelligence report</h2>
                    <div class="business-research-markdown">{!! Str::markdown($research->raw_response) !!}</div>
                </div>
            @endif

            @if ($research->sources)
                <div class="app-card app-card-padded">
                    <h2 class="app-section-title">Web sources</h2>
                    <ul class="business-research-sources">
                        @foreach ($research->sources as $source)
                            <li>
                                @if (!empty($source['url']))
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener"
                                        class="business-research-link">
                                        {{ $source['title'] ?? $source['url'] }}
                                    </a>
                                @else
                                    <span class="business-research-source-title">{{ $source['title'] ?? 'Source' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <details class="app-details app-card app-card-padded">
                <summary>Raw markdown source</summary>
                <pre class="business-research-raw">{{ $research->raw_response }}</pre>
                @if ($research->model_used)
                    <p class="business-research-meta">Model: {{ $research->model_used }} · Tokens:
                        {{ $research->tokens_used ?? 'N/A' }}</p>
                @endif
            </details>
        @endif

        <div class="business-research-actions">
            <form action="{{ route('admin.business-research.retry', $research) }}" method="POST">
                @csrf
                <button type="submit" class="app-btn app-btn-secondary">Re-run research</button>
            </form>
            <form action="{{ route('admin.business-research.destroy', $research) }}" method="POST"
                onsubmit="return confirm('Delete this research?')">
                @csrf @method('DELETE')
                <button type="submit" class="app-btn app-btn-ghost-danger">Delete</button>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    @if (!$research->isComplete())
        <script>
            (function() {
                const start = window.startProgressPoll;
                if (!start) return;

                start('{{ route('admin.business-research.status', $research) }}', (data) => {
                    if (data.complete) {
                        if (window.showToast) {
                            window.showToast('Business research complete.', 'success');
                        }
                        setTimeout(() => location.reload(), 400);
                        return false;
                    }

                    return true;
                });
            })();
        </script>
    @endif
@endpush
