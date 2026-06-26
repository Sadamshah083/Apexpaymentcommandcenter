@extends('layouts.admin')

@section('title', $lead->business_name)

@section('content')
<div class="mb-6">
    <a href="{{ route('crm.show', $crm) }}" class="text-indigo-600 text-sm">&larr; Back to {{ $crm->name }}</a>
    <h2 class="text-2xl font-bold mt-2">{{ $lead->business_name }}</h2>
    <p class="text-slate-600 text-sm">Row {{ $lead->row_number }} · {{ $lead->fullAddress() ?: 'No address' }}</p>
</div>

@if($lead->status === 'processing' || $lead->status === 'pending')
    <div id="progress-banner" class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-6 text-amber-900 text-sm">
        Research in progress… this page will refresh automatically.
        @if($lead->status === 'processing')
            <form action="{{ route('crm.leads.retry', [$crm, $lead]) }}" method="POST" class="inline ml-2">
                @csrf
                <button type="submit" class="text-indigo-700 underline text-xs">Re-run now</button>
            </form>
        @endif
    </div>
@endif

@if($lead->status === 'failed')
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
        <p class="font-medium text-red-800">Research failed</p>
        <p class="text-sm text-red-700 mt-1">{{ $lead->error_message }}</p>
        <form action="{{ route('crm.leads.retry', [$crm, $lead]) }}" method="POST" class="mt-3">
            @csrf
            <button type="submit" class="text-sm bg-red-600 text-white px-3 py-1 rounded">Retry</button>
        </form>
    </div>
@endif

<div class="grid md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs uppercase text-slate-500">Owner</p>
        <p class="text-lg font-bold mt-1">{{ $lead->owner_name ?? 'Not Publicly Available' }}</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs uppercase text-slate-500">Payment Processor</p>
        <p class="text-lg font-bold mt-1 text-indigo-700">{{ $lead->payment_processor ?? 'Not Publicly Available' }}</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs uppercase text-slate-500">Phone</p>
        <p class="text-sm font-semibold mt-1">{{ $lead->displayPhone() ?? 'Not Publicly Available' }}</p>
    </div>
    <div class="bg-white rounded-xl border p-4">
        <p class="text-xs uppercase text-slate-500">Email</p>
        <p class="text-sm font-semibold mt-1 break-all">{{ $lead->displayEmail() ?? 'Not Publicly Available' }}</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">Input Data (from CSV)</h3>
        <dl class="text-sm space-y-2">
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Business</dt><dd>{{ $lead->business_name }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Address</dt><dd>{{ $lead->fullAddress() ?: '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Website</dt><dd>{{ $lead->website ?? '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Input Phone</dt><dd>{{ $lead->input_phone ?? '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Input Email</dt><dd>{{ $lead->input_email ?? '—' }}</dd></div>
        </dl>
        @if($lead->extra_fields)
            <h4 class="font-medium mt-4 mb-2 text-sm">Extra CSV Columns</h4>
            <dl class="text-xs space-y-1">
                @foreach($lead->extra_fields as $key => $val)
                    <div class="flex justify-between"><dt class="text-slate-500">{{ $key }}</dt><dd>{{ Str::limit($val, 40) }}</dd></div>
                @endforeach
            </dl>
        @endif
    </div>

    <div class="bg-white rounded-xl border p-5">
        <h3 class="font-semibold mb-3">Enriched Intelligence</h3>
        <dl class="text-sm space-y-2">
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Verified Address</dt><dd class="text-right max-w-[60%]">{{ $lead->physical_address ?? '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Primary Service</dt><dd>{{ $lead->primary_service ?? '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Operating Hours</dt><dd class="text-right max-w-[60%]">{{ Str::limit($lead->operating_hours ?? '—', 80) }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">POS / Software</dt><dd>{{ $lead->field_service_software ?? $lead->pos_system ?? '—' }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Confidence</dt><dd>{{ ucfirst($lead->confidence ?? 'unknown') }}</dd></div>
            <div class="flex justify-between border-b pb-2"><dt class="text-slate-500">Model</dt><dd class="text-xs">{{ $lead->model_used ?? '—' }}</dd></div>
        </dl>
    </div>
</div>

@if($lead->raw_response)
    <div class="bg-white rounded-xl border p-6 mb-6 prose prose-slate max-w-none">
        <h3 class="text-lg font-semibold mb-4 not-prose">Full AI Report</h3>
        {!! Str::markdown($lead->raw_response) !!}
    </div>
@endif

@if($lead->sources)
    <div class="bg-white rounded-xl border p-5 mb-6">
        <h3 class="font-semibold mb-3">Sources</h3>
        <ul class="space-y-2 text-sm">
            @foreach($lead->sources as $source)
                <li>
                    @if(!empty($source['url']))
                        <a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">{{ $source['title'] ?? $source['url'] }}</a>
                    @else
                        {{ $source['title'] ?? 'Source' }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
@endsection

@push('scripts')
@if(!$lead->isComplete())
<script>
(function () {
    const start = window.startProgressPoll;
    if (!start) return;

    start('{{ route('crm.leads.status', [$crm, $lead]) }}', (data) => {
        if (data.complete) {
            if (window.showToast) {
                window.showToast('Lead research complete.', 'success');
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
