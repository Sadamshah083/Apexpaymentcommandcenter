@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', $list->name)

@php
    $routePrefix = request()->is('admin*') ? 'admin.' : 'portal.';
    $isProcessing = in_array($list->status, ['pending', 'processing', 'verifying'], true);
@endphp

@section('content')
<div class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:justify-between lg:items-start gap-4">
        <div>
            <a href="{{ route($routePrefix.'lists.index') }}" class="text-xs font-bold text-slate-500 hover:text-slate-800 underline">← All batches</a>
            <h2 class="text-2xl font-bold text-slate-900 mt-2">{{ $list->name }}</h2>
            <p class="text-sm text-slate-500 mt-1">
                {{ $list->source_file }} · {{ number_format($list->total_count) }} emails
                @if($list->user)
                    · Uploaded by {{ $list->user->name }}
                @endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($list->status === 'paused')
                <form method="POST" action="{{ route($routePrefix.'lists.resume', $list) }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-lg text-sm font-bold bg-emerald-600 text-white hover:bg-emerald-700">Resume</button>
                </form>
            @elseif($isProcessing)
                <form method="POST" action="{{ route($routePrefix.'lists.pause', $list) }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-lg text-sm font-bold bg-amber-600 text-white hover:bg-amber-700">Pause</button>
                </form>
            @endif
            <a href="{{ route($routePrefix.'lists.export', [$list, 'filter' => 'valid']) }}" class="px-3 py-2 rounded-lg text-sm font-bold bg-emerald-600 text-white hover:bg-emerald-700">Export valid</a>
            <a href="{{ route($routePrefix.'lists.export', [$list, 'filter' => 'valid_risky']) }}" class="px-3 py-2 rounded-lg text-sm font-bold bg-amber-600 text-white hover:bg-amber-700">Export valid+risky</a>
            <a href="{{ route($routePrefix.'lists.export', [$list, 'filter' => 'all']) }}" class="px-3 py-2 rounded-lg text-sm font-bold bg-slate-700 text-white hover:bg-slate-800">Export all</a>
        </div>
    </div>

    <div id="progress-panel" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 {{ $list->status === 'completed' || $list->status === 'empty' ? 'hidden' : '' }}">
        <div class="flex justify-between mb-2">
            <span class="text-sm font-bold text-slate-800">Verification progress</span>
            <span id="progress-text" class="text-sm text-slate-500">{{ $list->progress_percent }}%</span>
        </div>
        <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
            <div id="progress-bar" class="bg-indigo-600 h-3 rounded-full progress-bar-live" style="width: {{ $list->progress_percent }}%"></div>
        </div>
        <p id="progress-status" class="text-xs text-slate-500 mt-2">Status: {{ $list->status }} · Checking DNS, MX, disposable lists, and SMTP from the internet</p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center">
            <p id="stat-valid" class="text-2xl font-black text-emerald-700">{{ number_format($list->valid_count) }}</p>
            <p class="text-xs text-emerald-600 font-bold uppercase tracking-wide">Valid</p>
        </div>
        <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-center">
            <p id="stat-invalid" class="text-2xl font-black text-rose-700">{{ number_format($list->invalid_count) }}</p>
            <p class="text-xs text-rose-600 font-bold uppercase tracking-wide">Invalid</p>
        </div>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center">
            <p id="stat-risky" class="text-2xl font-black text-amber-700">{{ number_format($list->risky_count) }}</p>
            <p class="text-xs text-amber-600 font-bold uppercase tracking-wide">Risky</p>
        </div>
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 text-center">
            <p id="stat-unknown" class="text-2xl font-black text-slate-700">{{ number_format($list->unknown_count) }}</p>
            <p class="text-xs text-slate-600 font-bold uppercase tracking-wide">Unknown</p>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <a href="{{ route($routePrefix.'lists.show', $list) }}" class="px-3 py-1.5 rounded-lg text-sm font-semibold {{ !request('status') ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-700' }}">All</a>
        @foreach(['valid', 'invalid', 'risky', 'unknown', 'pending'] as $status)
            <a href="{{ route($routePrefix.'lists.show', [$list, 'status' => $status]) }}" class="px-3 py-1.5 rounded-lg text-sm font-semibold {{ request('status') === $status ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-700' }}">{{ ucfirst($status) }}</a>
        @endforeach
    </div>

    <x-data-table :paginator="$contacts" min-width="900px">
        <table>
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Domain</th>
                    <th>Status</th>
                    <th>Score</th>
                    <th>MX (internet)</th>
                    <th>SMTP</th>
                    <th>Type</th>
                    <th>Tags</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                @forelse($contacts as $contact)
                    @php
                        $summary = $contact->verificationSummary();
                        $badgeClass = match($contact->status) {
                            'valid' => 'bg-emerald-100 text-emerald-800',
                            'invalid' => 'bg-rose-100 text-rose-800',
                            'risky' => 'bg-amber-100 text-amber-800',
                            'unknown' => 'bg-slate-100 text-slate-800',
                            'pending' => 'bg-blue-100 text-blue-800 animate-pulse',
                            default => 'bg-slate-100 text-slate-800',
                        };
                    @endphp
                    <tr>
                        <td class="font-mono text-xs text-slate-800">{{ $contact->email }}</td>
                        <td class="text-slate-600">{{ $contact->domain }}</td>
                        <td>
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $badgeClass }}">{{ $contact->status }}</span>
                        </td>
                        <td class="font-semibold">{{ $contact->final_score ?? '—' }}</td>
                        <td class="text-xs text-slate-600 max-w-[10rem] truncate" title="{{ $summary['mx'] }}">{{ $summary['mx'] ?? '—' }}</td>
                        <td class="text-xs text-slate-600">{{ $summary['smtp'] ?? '—' }}</td>
                        <td class="text-xs text-slate-600">{{ $summary['provider'] ?? '—' }}</td>
                        <td class="text-xs text-slate-500">{{ implode(', ', $contact->tags ?? []) ?: '—' }}</td>
                        <td class="text-xs text-slate-500 max-w-[12rem] truncate" title="{{ $contact->failure_reason }}">{{ $contact->failure_reason ? Str::limit($contact->failure_reason, 48) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center py-10 text-slate-500">No results yet. Verification may still be running.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
</div>
@endsection

@push('scripts')
<script>
@if($list->status !== 'completed' && $list->status !== 'empty' && $list->status !== 'paused')
(function () {
    const start = window.startProgressPoll;
    if (!start) return;

    start('{{ route($routePrefix."lists.progress", $list) }}', (data) => {
        document.getElementById('progress-bar').style.width = data.progress + '%';
        document.getElementById('progress-text').textContent = data.progress + '% (' + data.processed + '/' + data.total + ')';
        document.getElementById('progress-status').textContent = 'Status: ' + data.status + ' · Checking DNS, MX, disposable lists, and SMTP from the internet';
        document.getElementById('stat-valid').textContent = Number(data.valid_count).toLocaleString();
        document.getElementById('stat-invalid').textContent = Number(data.invalid_count).toLocaleString();
        document.getElementById('stat-risky').textContent = Number(data.risky_count).toLocaleString();
        document.getElementById('stat-unknown').textContent = Number(data.unknown_count).toLocaleString();

        if (data.complete) {
            document.getElementById('progress-panel').classList.add('hidden');
            if (window.showToast) {
                window.showToast('Email verification complete.', 'success');
            }
            setTimeout(() => location.reload(), 400);
            return false;
        }

        return !data.complete && data.status !== 'empty' && data.status !== 'paused';
    });
})();
@endif
</script>
@endpush
