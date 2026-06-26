@extends('layouts.admin')

@section('title', 'Research Report')

@section('content')
<div class="app-page space-y-6">
    <div>
        <x-back-link :href="route('admin.business-research.index')" label="All research" />
        <h1 class="app-page-title mt-2">{{ $research->business_name }}</h1>
        @if($research->address)
            <p class="app-page-subtitle">{{ $research->address }}</p>
        @endif
    </div>

    @if(!$research->isComplete())
        <div id="loading-panel" class="app-alert app-alert-warning">
            <p class="app-alert-title">Research in progress…</p>
            <p class="app-alert-desc">Multi-source web research: DuckDuckGo directories + Gemini 2.5 Pro with Google Search grounding. This may take 2–4 minutes.</p>
            <div class="app-progress-track mt-3">
                <div class="app-progress-fill animate-pulse" style="width: 40%"></div>
            </div>
        </div>
    @endif

    @if($research->status === 'failed')
        <div class="app-alert app-alert-danger">
            <p class="app-alert-title">Research failed</p>
            <p class="app-alert-desc">{{ $research->error_message }}</p>
            @if(str_contains($research->error_message ?? '', 'OpenRouter'))
                <p class="app-alert-desc mt-2">This is an old error from before Gemini was enabled. Click Retry — research now uses Gemini only.</p>
            @endif
            @if(str_contains($research->error_message ?? '', 'Gemini'))
                <p class="app-alert-desc mt-2">Confirm <code class="text-xs bg-rose-100 px-1 rounded">GEMINI_API_KEY</code> in <code class="text-xs bg-rose-100 px-1 rounded">.env</code> is from <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="app-link">Google AI Studio</a>.</p>
            @endif
            <form action="{{ route('admin.business-research.retry', $research) }}" method="POST" class="mt-3">
                @csrf
                <button type="submit" class="app-btn app-btn-primary app-btn-sm">Retry</button>
            </form>
        </div>
    @endif

    @if($research->status === 'completed')
        @php $data = $research->structured_data ?? []; @endphp

        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Direct owner</p>
                <p class="app-kpi-value text-xl">{{ $research->owner_name ?? 'Not Publicly Available' }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Payment processor</p>
                <p class="app-kpi-value text-xl">{{ $research->payment_processor ?? 'Not Publicly Available' }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Phone</p>
                <p class="text-sm font-semibold text-zinc-900 mt-1">{{ $data['direct_phone'] ?? ($research->phones[0] ?? 'Not Publicly Available') }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Email</p>
                <p class="text-sm font-semibold text-zinc-900 mt-1 break-all">{{ $data['direct_email'] ?? ($research->emails[0] ?? 'Not Publicly Available') }}</p>
            </div>
        </div>

        @if($research->raw_response)
            <div class="app-card app-card-padded prose prose-zinc max-w-none">
                <h2 class="app-section-title mb-4 not-prose">Full intelligence report</h2>
                {!! Str::markdown($research->raw_response) !!}
            </div>
        @endif

        @if($research->sources)
            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-4">Web sources</h2>
                <ul class="space-y-2 text-sm">
                    @foreach($research->sources as $source)
                        <li class="border-b border-zinc-100 pb-2 last:border-0">
                            @if(!empty($source['url']))
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="app-link font-medium">
                                    {{ $source['title'] ?? $source['url'] }}
                                </a>
                            @else
                                <span class="font-medium text-zinc-900">{{ $source['title'] ?? 'Source' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <details class="app-details app-card app-card-padded">
            <summary>Raw markdown source</summary>
            <pre class="mt-3 whitespace-pre-wrap text-xs text-zinc-600 overflow-x-auto font-mono">{{ $research->raw_response }}</pre>
            @if($research->model_used)
                <p class="text-xs text-zinc-400 mt-2">Model: {{ $research->model_used }} · Tokens: {{ $research->tokens_used ?? 'N/A' }}</p>
            @endif
        </details>
    @endif

    <div class="flex flex-wrap gap-2">
        <form action="{{ route('admin.business-research.retry', $research) }}" method="POST">
            @csrf
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Re-run research</button>
        </form>
        <form action="{{ route('admin.business-research.destroy', $research) }}" method="POST" onsubmit="return confirm('Delete this research?')">
            @csrf @method('DELETE')
            <button type="submit" class="app-btn app-btn-ghost-danger app-btn-sm">Delete</button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
@if(!$research->isComplete())
<script>
(function () {
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
