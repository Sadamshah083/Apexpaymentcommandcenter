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

    <x-data-table :paginator="$lists">
        <table>
            <thead>
                <tr>
                    <th>Batch name</th>
                    <th>Uploaded by</th>
                    <th>Total</th>
                    <th>Valid</th>
                    <th>Invalid</th>
                    <th>Status</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody id="workspace-sync-email-lists-body" data-sync-mode="{{ $lists->currentPage() > 1 ? 'patch' : 'replace' }}">
                @forelse($lists as $list)
                    @php
                        $statusClass = match($list->status) {
                            'completed' => 'app-badge app-badge-success',
                            'verifying', 'processing' => 'app-badge app-badge-warning',
                            'paused' => 'app-badge app-badge-muted',
                            'failed', 'empty' => 'app-badge app-badge-danger',
                            default => 'app-badge app-badge-muted',
                        };
                    @endphp
                    <tr data-list-id="{{ $list->id }}">
                        <td>
                            <div class="font-semibold text-zinc-900">{{ $list->name }}</div>
                            <div class="text-xs text-zinc-400 mt-0.5">{{ $list->source_file }}</div>
                        </td>
                        <td class="text-zinc-600">{{ $list->user?->name ?? '—' }}</td>
                        <td>{{ number_format($list->total_count) }}</td>
                        <td class="text-emerald-600 font-semibold">{{ number_format($list->valid_count) }}</td>
                        <td class="text-rose-600 font-semibold">{{ number_format($list->invalid_count) }}</td>
                        <td>
                            <span class="{{ $statusClass }}">{{ $list->status }}</span>
                        </td>
                        <td class="text-right">
                            <a href="{{ request()->is('admin*') ? route('admin.lists.show', $list) : route('portal.lists.show', $list) }}" class="app-link text-sm">Open results</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center py-12">
                            <p class="text-slate-500 font-medium">No verification batches yet.</p>
                            <p class="text-xs text-slate-400 mt-2">Upload a CSV or TXT file with one email per line to get started.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-data-table>
</div>
@endsection
