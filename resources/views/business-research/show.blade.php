@extends('layouts.admin')

@section('title', 'Research Report')

@section('content')
<div class="mb-6">
    <a href="{{ route('business-research.index') }}" class="text-indigo-600 text-sm">&larr; Back</a>
    <h2 class="text-2xl font-bold mt-2">{{ $research->business_name }}</h2>
    @if($research->address)
        <p class="text-slate-600 text-sm">{{ $research->address }}</p>
    @endif
</div>

@if(!$research->isComplete())
    <div id="loading-panel" class="bg-amber-50 border border-amber-200 rounded-xl p-6 mb-6">
        <p class="font-medium text-amber-900">Research in progress…</p>
        <p class="text-sm text-amber-800 mt-1">Multi-source web research: DuckDuckGo directories + Gemini 2.5 Pro with Google Search grounding (Maps, Yelp, BBB, social, SOS filings). This may take 2–4 minutes.</p>
        <div class="mt-3 w-full bg-amber-200 rounded-full h-2 animate-pulse"></div>
    </div>
@endif

@if($research->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
        <p class="font-medium text-red-800">Research failed</p>
        <p class="text-sm text-red-700 mt-1">{{ $research->error_message }}</p>
        @if(str_contains($research->error_message ?? '', 'OpenRouter'))
            <p class="text-sm text-red-600 mt-2">This is an <strong>old error</strong> from before Gemini was enabled. Click <strong>Retry</strong> below — research now uses Gemini only and will not call OpenRouter.</p>
        @endif
        @if(str_contains($research->error_message ?? '', 'Gemini'))
            <p class="text-sm text-red-600 mt-2">Confirm <code class="text-xs bg-red-100 px-1 rounded">GEMINI_API_KEY</code> in <code class="text-xs bg-red-100 px-1 rounded">.env</code> is from <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" class="underline">Google AI Studio</a> (starts with <code class="text-xs">AIzaSy</code>) and billing is enabled.</p>
        @endif
        <form action="{{ route('business-research.retry', $research) }}" method="POST" class="mt-3">
            @csrf
            <button type="submit" class="text-sm bg-red-600 text-white px-3 py-1 rounded">Retry</button>
        </form>
    </div>
@endif

@if($research->status === 'completed')
    @php $data = $research->structured_data ?? []; @endphp

    <div class="grid md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl border shadow-sm p-4">
            <p class="text-xs uppercase text-slate-500 font-medium">Direct Owner</p>
            <p class="text-lg font-bold mt-1">{{ $research->owner_name ?? 'Not Publicly Available' }}</p>
        </div>
        <div class="bg-white rounded-xl border shadow-sm p-4">
            <p class="text-xs uppercase text-slate-500 font-medium">Payment Processor</p>
            <p class="text-lg font-bold mt-1 text-indigo-700">{{ $research->payment_processor ?? 'Not Publicly Available' }}</p>
        </div>
        <div class="bg-white rounded-xl border shadow-sm p-4">
            <p class="text-xs uppercase text-slate-500 font-medium">Phone</p>
            <p class="text-sm font-semibold mt-1">{{ $data['direct_phone'] ?? ($research->phones[0] ?? 'Not Publicly Available') }}</p>
        </div>
        <div class="bg-white rounded-xl border shadow-sm p-4">
            <p class="text-xs uppercase text-slate-500 font-medium">Email</p>
            <p class="text-sm font-semibold mt-1 break-all">{{ $data['direct_email'] ?? ($research->emails[0] ?? 'Not Publicly Available') }}</p>
        </div>
    </div>

    @if($research->raw_response)
        <div class="bg-white rounded-xl border shadow-sm p-6 mb-6 prose prose-slate max-w-none">
            <h3 class="text-lg font-semibold mb-4 not-prose">Full Intelligence Report</h3>
            {!! Str::markdown($research->raw_response) !!}
        </div>
    @endif

    @if($research->sources)
        <div class="bg-white rounded-xl border p-5 mb-6">
            <h3 class="font-semibold mb-3">Web Sources (Google Search grounding)</h3>
            <ul class="space-y-2 text-sm">
                @foreach($research->sources as $source)
                    <li class="border-b pb-2 last:border-0">
                        @if(!empty($source['url']))
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline font-medium">
                                {{ $source['title'] ?? $source['url'] }}
                            </a>
                        @else
                            <span class="font-medium">{{ $source['title'] ?? 'Source' }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <details class="bg-slate-50 rounded-xl border p-4 text-sm">
        <summary class="cursor-pointer font-medium text-slate-700">Raw markdown source</summary>
        <pre class="mt-3 whitespace-pre-wrap text-xs text-slate-600 overflow-x-auto">{{ $research->raw_response }}</pre>
        @if($research->model_used)
            <p class="text-xs text-slate-400 mt-2">Model: {{ $research->model_used }} · Tokens: {{ $research->tokens_used ?? 'N/A' }}</p>
        @endif
    </details>
@endif

<div class="mt-6 flex gap-2">
    <form action="{{ route('business-research.retry', $research) }}" method="POST">
        @csrf
        <button type="submit" class="text-sm border px-3 py-2 rounded-lg hover:bg-slate-50">Re-run research</button>
    </form>
    <form action="{{ route('business-research.destroy', $research) }}" method="POST" onsubmit="return confirm('Delete this research?')">
        @csrf @method('DELETE')
        <button type="submit" class="text-sm text-red-600 border border-red-200 px-3 py-2 rounded-lg">Delete</button>
    </form>
</div>
@endsection

@push('scripts')
@if(!$research->isComplete())
<script>
(function () {
    const start = window.startProgressPoll;
    if (!start) return;

    start('{{ route('business-research.status', $research) }}', (data) => {
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
