@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Content Report')

@section('content')
<div class="mb-6">
    <a href="{{ request()->is('admin*') ? route('admin.content.index') : route('portal.content.index') }}" class="text-indigo-600 text-sm">&larr; Back</a>
    <h2 class="text-2xl font-bold mt-2">{{ $analysis->title ?? 'Content Analysis' }}</h2>
</div>

<div class="grid md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border p-6 text-center">
        <p class="text-sm text-slate-500">Mail-Tester Style Score</p>
        <p class="text-4xl font-bold {{ ($result['mail_tester_score'] ?? 0) >= 7 ? 'text-green-600' : (($result['mail_tester_score'] ?? 0) >= 4 ? 'text-amber-600' : 'text-red-600') }}">
            {{ $result['mail_tester_score'] ?? 0 }}/10
        </p>
        <p class="text-xs text-slate-500 mt-1">Higher is better</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-6 text-center">
        <p class="text-sm text-slate-500">Spam Risk Score</p>
        <p class="text-4xl font-bold text-red-600">{{ $result['spam_score'] ?? 0 }}</p>
        <p class="text-xs text-slate-500 mt-1">Lower is better (threshold: 5.0)</p>
    </div>
    <div class="bg-white rounded-xl shadow-sm border p-6 text-center">
        <p class="text-sm text-slate-500">Risk Level</p>
        <p class="text-2xl font-bold uppercase">{{ $result['risk_level'] ?? 'unknown' }}</p>
    </div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-3">Category Breakdown</h3>
        @foreach($result['scores'] ?? [] as $category => $score)
            @if($score != 0)
                <div class="flex justify-between py-1 text-sm border-b">
                    <span class="capitalize">{{ str_replace('_', ' ', $category) }}</span>
                    <span class="font-mono {{ $score > 0 ? 'text-red-600' : 'text-green-600' }}">{{ $score > 0 ? '+' : '' }}{{ $score }}</span>
                </div>
            @endif
        @endforeach
    </div>

    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-3">Suggestions</h3>
        @forelse($result['suggestions'] ?? [] as $suggestion)
            <p class="text-sm py-1 border-b text-slate-700">{{ $suggestion }}</p>
        @empty
            <p class="text-sm text-green-600">No major issues detected.</p>
        @endforelse
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border p-6 mb-6">
    <h3 class="font-semibold mb-2">Subject (highlighted)</h3>
    <p class="text-lg">{!! $highlightedSubject !!}</p>
</div>

<div class="bg-white rounded-xl shadow-sm border p-6">
    <h3 class="font-semibold mb-2">Body (highlighted)</h3>
    <div class="prose max-w-none text-sm leading-relaxed whitespace-pre-wrap">{!! $highlightedBody !!}</div>
</div>
@endsection
