@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Bulk Email Verifier')

@section('content')
<div class="app-page">
    <div class="app-page-header flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h1 class="app-page-title">Bulk Email Verifier</h1>
            <p class="app-page-subtitle">Upload email lists and verify deliverability using live DNS, MX, and SMTP checks.</p>
        </div>
        <a href="{{ request()->is('admin*') ? route('admin.lists.create') : route('portal.lists.create') }}" class="app-btn app-btn-primary">
            Upload emails
        </a>
    </div>

    <div class="app-table-wrap">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 border-b border-slate-100">
            <tr>
                <th class="text-left p-4 font-bold text-slate-700">Batch name</th>
                <th class="text-left p-4 font-bold text-slate-700">Uploaded by</th>
                <th class="text-left p-4 font-bold text-slate-700">Total</th>
                <th class="text-left p-4 font-bold text-slate-700">Valid</th>
                <th class="text-left p-4 font-bold text-slate-700">Invalid</th>
                <th class="text-left p-4 font-bold text-slate-700">Status</th>
                <th class="text-right p-4 font-bold text-slate-700">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
            @forelse($lists as $list)
                @php
                    $statusClass = match($list->status) {
                        'completed' => 'bg-emerald-100 text-emerald-800',
                        'verifying', 'processing' => 'bg-amber-100 text-amber-800 animate-pulse',
                        'paused' => 'bg-orange-100 text-orange-800',
                        'failed', 'empty' => 'bg-rose-100 text-rose-800',
                        default => 'bg-slate-100 text-slate-700',
                    };
                @endphp
                <tr class="hover:bg-slate-50/80 transition-colors">
                    <td class="p-4">
                        <div class="font-semibold text-slate-900">{{ $list->name }}</div>
                        <div class="text-xs text-slate-400 mt-0.5">{{ $list->source_file }}</div>
                    </td>
                    <td class="p-4 text-slate-600">{{ $list->user?->name ?? '—' }}</td>
                    <td class="p-4">{{ number_format($list->total_count) }}</td>
                    <td class="p-4 text-emerald-600 font-semibold">{{ number_format($list->valid_count) }}</td>
                    <td class="p-4 text-rose-600 font-semibold">{{ number_format($list->invalid_count) }}</td>
                    <td class="p-4">
                        <span class="px-2.5 py-1 rounded-full text-xs font-bold uppercase {{ $statusClass }}">{{ $list->status }}</span>
                    </td>
                    <td class="p-4 text-right">
                        <a href="{{ request()->is('admin*') ? route('admin.lists.show', $list) : route('portal.lists.show', $list) }}" class="text-indigo-600 hover:text-indigo-800 font-semibold text-sm">Open results</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="p-12 text-center">
                        <p class="text-slate-500 font-medium">No verification batches yet.</p>
                        <p class="text-xs text-slate-400 mt-2">Upload a CSV or TXT file with one email per line to get started.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<x-pagination :paginator="$lists" class="mt-4" />
</div>
@endsection
