@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Deliverability Report')

@section('content')
<div class="mb-6">
    <a href="{{ request()->is('admin*') ? route('admin.deliverability.index') : route('portal.deliverability.index') }}" class="text-indigo-600 text-sm">&larr; Back</a>
    <h2 class="text-2xl font-bold mt-2">{{ $test->domain }}</h2>
    <p class="text-slate-600">Deliverability score: <span class="text-3xl font-bold {{ $test->overall_score >= 7 ? 'text-green-600' : ($test->overall_score >= 4 ? 'text-amber-600' : 'text-red-600') }}">{{ $test->overall_score }}/10</span></p>
</div>

@if($test->status === 'pending' || $test->status === 'processing')
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        Test is {{ $test->status }}... Refresh page in a few seconds.
        <script>setTimeout(() => location.reload(), 3000);</script>
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

<div class="grid md:grid-cols-2 gap-4 mb-6">
    @foreach($checks as $name => $result)
        @if($result)
            @php
                $status = $result['status'] ?? 'unknown';
                $statusBadge = match($status) {
                    'pass' => 'bg-green-100 text-green-800',
                    'warn' => 'bg-amber-100 text-amber-800',
                    'fail' => 'bg-red-100 text-red-800',
                    default => 'bg-slate-100 text-slate-800',
                };
            @endphp
            <div class="bg-white rounded-xl shadow-sm border p-5">
                <div class="flex justify-between items-center mb-2">
                    <h3 class="font-semibold">{{ $name }}</h3>
                    <span class="px-2 py-1 rounded text-xs {{ $statusBadge }} uppercase">{{ $status }}</span>
                </div>
                <p class="text-sm text-slate-600">{{ $result['message'] ?? '' }}</p>
                @if(isset($result['score']))
                    <p class="text-xs mt-2">Score: {{ $result['score'] }}/10</p>
                @endif
                @if(!empty($result['record']))
                    <pre class="text-xs bg-slate-50 p-2 rounded mt-2 overflow-x-auto">{{ Str::limit($result['record'], 200) }}</pre>
                @endif
                @if(!empty($result['recommendation']))
                    <p class="text-xs text-indigo-700 mt-2 bg-indigo-50 p-2 rounded">{{ $result['recommendation'] }}</p>
                @endif
            </div>
        @endif
    @endforeach
</div>

@if($test->recommendations)
    <div class="bg-white rounded-xl shadow-sm border p-6">
        <h3 class="font-semibold mb-3">Recommendations</h3>
        <ul class="list-disc list-inside space-y-1 text-sm text-slate-700">
            @foreach($test->recommendations as $rec)
                <li>{{ $rec }}</li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
