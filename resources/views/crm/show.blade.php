@extends('layouts.admin')

@section('title', $crm->name)

@section('content')
<div class="mb-6">
    <a href="{{ route('crm.index') }}" class="text-indigo-600 text-sm">&larr; Back to CRM</a>
    <div class="flex flex-wrap justify-between items-start gap-4 mt-2">
        <div>
            <h2 class="text-2xl font-bold">{{ $crm->name }}</h2>
            <p class="text-slate-600 text-sm">{{ $crm->original_filename }} · {{ $crm->total_leads }} leads</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <form action="{{ route('crm.reupload', $crm) }}" method="POST" enctype="multipart/form-data" class="flex items-center gap-2">
                @csrf
                <label class="text-sm border px-3 py-2 rounded-lg hover:bg-slate-50 cursor-pointer">
                    Update CSV
                    <input type="file" name="file" accept=".csv,.txt" class="hidden" onchange="if(confirm('Re-import CSV? Completed research is kept unless business name or address changed.')) this.form.submit()">
                </label>
            </form>
            <a href="{{ route('crm.export', $crm) }}" class="text-sm border px-3 py-2 rounded-lg hover:bg-slate-50">Export CSV</a>
            @if($crm->failed_count > 0 || $crm->processing_count > 0)
                <form action="{{ route('crm.retry-failed', $crm) }}" method="POST" onsubmit="return confirm('Re-queue all failed/stuck leads?')">
                    @csrf
                    <button type="submit" class="text-sm bg-indigo-600 text-white px-3 py-2 rounded-lg">Re-run Failed ({{ $crm->failed_count + $crm->processing_count }})</button>
                </form>
            @endif
            <form action="{{ route('crm.destroy', $crm) }}" method="POST" onsubmit="return confirm('Delete this campaign and all leads?')">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm text-red-600 border border-red-200 px-3 py-2 rounded-lg">Delete</button>
            </form>
        </div>
    </div>
</div>


@if($crm->import_error)
    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6 text-red-800 text-sm">{{ $crm->import_error }}</div>
@endif

@if($crm->status === 'pending' && $crm->total_leads === 0)
    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-blue-800 text-sm">Waiting to import CSV…</div>
@endif

@if(!$crm->isComplete())
    <div id="progress-panel" class="bg-amber-50 border border-amber-200 rounded-xl p-5 mb-6">
        <div class="flex justify-between items-center mb-2">
            <p class="font-medium text-amber-900">AI research in progress…</p>
            <span id="progress-text" class="text-sm text-amber-800">{{ $crm->progressPercent() }}%</span>
        </div>
        <div class="w-full bg-amber-200 rounded-full h-3">
            <div id="progress-bar" class="bg-indigo-600 h-3 rounded-full transition-all" style="width: {{ $crm->progressPercent() }}%"></div>
        </div>
        <p class="text-xs text-amber-700 mt-2">
            <span id="progress-stats">{{ $crm->completed_count }} done · {{ $crm->processing_count }} processing · {{ $crm->pending_count }} pending · {{ $crm->failed_count }} failed</span>
        </p>
        <p class="text-xs text-amber-600 mt-2">Faster: run <code class="bg-white/70 px-1 rounded">php artisan queue:work database --sleep=1</code> in 3–4 terminals.</p>
    </div>
@endif

<div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold">{{ $crm->total_leads }}</p>
        <p class="text-xs text-slate-500">Total</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-emerald-600">{{ $enrichedCount ?? 0 }}</p>
        <p class="text-xs text-slate-500">Enriched</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-green-600">{{ $crm->completed_count }}</p>
        <p class="text-xs text-slate-500">Completed</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-amber-600">{{ $crm->processing_count + $crm->pending_count }}</p>
        <p class="text-xs text-slate-500">In Queue</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-red-600">{{ $crm->failed_count }}</p>
        <p class="text-xs text-slate-500">Failed</p>
    </div>
    <div class="bg-white rounded-xl border p-4 text-center">
        <p class="text-2xl font-bold text-indigo-600">{{ $crm->progressPercent() }}%</p>
        <p class="text-xs text-slate-500">Progress</p>
    </div>
</div>

@if($crm->column_mapping)
    @php
        $mappingLabels = [
            'business_name' => 'Business / Company Name',
            'full_address' => 'Full Address',
            'address' => 'Street Address',
            'address_line_2' => 'Address Line 2',
            'city' => 'City',
            'state' => 'State',
            'zip_code' => 'ZIP',
            'country' => 'Country',
            'website' => 'Website',
            'input_phone' => 'Phone',
            'input_email' => 'Email',
        ];
    @endphp
    <details class="mb-6 bg-slate-50 rounded-xl border p-4 text-sm">
        <summary class="cursor-pointer font-medium">CSV column mapping (auto-detected)</summary>
        <div class="mt-3 grid md:grid-cols-2 gap-2 text-xs">
            @foreach($mappingLabels as $field => $label)
                @if(!empty($crm->column_mapping[$field]))
                    <div class="bg-white rounded border px-2 py-1.5">
                        <span class="text-slate-500">{{ $label }}:</span>
                        <strong class="text-indigo-700">{{ $crm->column_mapping[$field] }}</strong>
                    </div>
                @endif
            @endforeach
        </div>
        @if($crm->csv_headers)
            <p class="text-xs text-slate-500 mt-3">All headers: {{ implode(', ', $crm->csv_headers) }}</p>
        @endif
    </details>
@endif

<div class="bg-white rounded-xl shadow-sm border mb-4 p-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium mb-1">Search</label>
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Business, owner, processor…"
                class="border rounded-lg px-3 py-2 text-sm w-48">
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">Status</label>
            <select name="status" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All</option>
                @foreach(['pending','processing','completed','failed','skipped'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium mb-1">Enriched data</label>
            <select name="enriched" class="border rounded-lg px-3 py-2 text-sm">
                <option value="">All leads</option>
                <option value="yes" @selected(request('enriched') === 'yes')>Enriched only</option>
                <option value="no" @selected(request('enriched') === 'no')>Not enriched</option>
            </select>
        </div>
        <button type="submit" class="text-sm bg-slate-800 text-white px-4 py-2 rounded-lg">Filter</button>
        @if(request()->hasAny(['q','status','enriched']))
            <a href="{{ route('crm.show', $crm) }}" class="text-sm text-slate-500 hover:underline">Clear</a>
        @endif
    </form>
    <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t">
        <span class="text-xs text-slate-500 self-center">Quick:</span>
        <a href="{{ route('crm.show', [$crm, 'enriched' => 'yes']) }}"
            class="text-xs px-3 py-1 rounded-full {{ request('enriched') === 'yes' ? 'bg-emerald-600 text-white' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
            Enriched ({{ $enrichedCount ?? 0 }})
        </a>
        <a href="{{ route('crm.show', [$crm, 'status' => 'pending']) }}"
            class="text-xs px-3 py-1 rounded-full {{ request('status') === 'pending' ? 'bg-amber-600 text-white' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' }}">
            Pending ({{ $crm->pending_count }})
        </a>
        <a href="{{ route('crm.show', [$crm, 'status' => 'failed']) }}"
            class="text-xs px-3 py-1 rounded-full {{ request('status') === 'failed' ? 'bg-red-600 text-white' : 'bg-red-50 text-red-800 hover:bg-red-100' }}">
            Failed ({{ $crm->failed_count }})
        </a>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border overflow-x-auto">
    <table class="w-full text-sm min-w-[900px]">
        <thead class="bg-slate-50 text-left">
            <tr>
                <th class="p-3">#</th>
                <th class="p-3">Business</th>
                <th class="p-3">Location</th>
                <th class="p-3">Owner</th>
                <th class="p-3">Phone</th>
                <th class="p-3">Processor</th>
                <th class="p-3">POS / Software</th>
                <th class="p-3">Status</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($leads as $lead)
                <tr class="border-t hover:bg-slate-50">
                    <td class="p-3 text-slate-400">{{ $lead->row_number }}</td>
                    <td class="p-3 font-medium">{{ Str::limit($lead->business_name, 35) }}</td>
                    <td class="p-3 text-slate-600">{{ Str::limit($lead->city ?? $lead->fullAddress(), 25) }}</td>
                    <td class="p-3">{{ $lead->owner_name ?? '—' }}</td>
                    <td class="p-3">{{ $lead->displayPhone() ?? '—' }}</td>
                    <td class="p-3 text-indigo-700">{{ Str::limit($lead->payment_processor ?? '—', 20) }}</td>
                    <td class="p-3">{{ Str::limit($lead->field_service_software ?? $lead->pos_system ?? '—', 18) }}</td>
                    <td class="p-3">
                        @php
                            $lb = match($lead->status) {
                                'completed' => 'bg-green-100 text-green-800',
                                'processing' => 'bg-blue-100 text-blue-800',
                                'failed' => 'bg-red-100 text-red-800',
                                default => 'bg-slate-100 text-slate-600',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded text-xs {{ $lb }}">{{ $lead->status }}</span>
                        @if($lead->isEnriched())
                            <span class="ml-1 px-2 py-0.5 rounded text-xs bg-emerald-100 text-emerald-800">enriched</span>
                        @endif
                    </td>
                    <td class="p-3">
                        <a href="{{ route('crm.leads.show', [$crm, $lead]) }}" class="text-indigo-600 hover:underline text-xs">View</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-8 text-center text-slate-500">No leads match your filters.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($leads->hasPages())
        <x-pagination :paginator="$leads" class="p-4 border-t" />
    @elseif($leads->total() > 0)
        <div class="p-4 border-t text-xs text-slate-500">
            Showing all {{ number_format($leads->total()) }} leads
        </div>
    @endif
</div>
@endsection

@push('scripts')
@if(!$crm->isComplete())
<script>
(function poll() {
    fetch('{{ route('crm.progress', $crm) }}')
        .then(r => r.json())
        .then(data => {
            document.getElementById('progress-bar').style.width = data.percent + '%';
            document.getElementById('progress-text').textContent = data.percent + '%';
            document.getElementById('progress-stats').textContent =
                data.completed + ' done · ' + data.processing + ' processing · ' + data.pending + ' pending · ' + data.failed + ' failed';
            if (data.complete) {
                if (window.showToast) {
                    window.showToast('Campaign research complete.', 'success');
                }
                setTimeout(() => location.reload(), 700);
            } else {
                setTimeout(poll, 4000);
            }
        })
        .catch(() => setTimeout(poll, 5000));
})();
</script>
@endif
@endpush
