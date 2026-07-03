@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Bulk Email Verifier')

@section('content')
    <div class="app-page email-lists-page">
        <div class="app-page-header flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div>
                <h1 class="app-page-title">Bulk Email Verifier</h1>
                <p class="app-page-subtitle">Upload email lists and verify deliverability using live DNS, MX, and SMTP
                    checks.</p>
            </div>
            <a href="{{ request()->is('admin*') ? route('admin.lists.create') : route('portal.lists.create') }}"
                class="app-btn app-btn-primary shrink-0">
                Upload emails
            </a>
        </div>

        <x-data-table :paginator="$lists" min-width="960px" class="email-lists-data-table">
            <table class="email-lists-table">
                <thead>
                    <tr>
                        <th>Batch name</th>
                        <th>Uploaded by</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Valid</th>
                        <th class="text-right">Invalid</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="workspace-sync-email-lists-body"
                    data-sync-mode="{{ $lists->currentPage() > 1 ? 'patch' : 'replace' }}">
                    @forelse($lists as $list)
                        @php
                            $statusClass = match ($list->status) {
                                'completed' => 'app-badge app-badge-success',
                                'verifying', 'processing' => 'app-badge app-badge-warning',
                                'paused' => 'app-badge app-badge-muted',
                                'failed', 'empty' => 'app-badge app-badge-danger',
                                default => 'app-badge app-badge-muted',
                            };
                        @endphp
                        <tr data-list-id="{{ $list->id }}">
                            <td>
                                <div class="email-lists-name">{{ $list->name }}</div>
                                <div class="email-lists-meta">{{ $list->source_file }}</div>
                            </td>
                            <td class="email-lists-cell">{{ $list->user?->name ?? '—' }}</td>
                            <td class="email-lists-cell text-right">
                                <span class="email-lists-num">{{ number_format($list->total_count) }}</span>
                            </td>
                            <td class="text-right">
                                <span class="email-lists-num is-success">{{ number_format($list->valid_count) }}</span>
                            </td>
                            <td class="text-right">
                                <span class="email-lists-num is-danger">{{ number_format($list->invalid_count) }}</span>
                            </td>
                            <td>
                                <span class="{{ $statusClass }}">{{ $list->status }}</span>
                            </td>
                            <td class="text-right">
                                <a href="{{ request()->is('admin*') ? route('admin.lists.show', $list) : route('portal.lists.show', $list) }}"
                                    class="app-btn app-btn-secondary app-btn-sm">Open results</a>
                            </td>
                        </tr>
                    @empty
                        <tr class="email-lists-empty-row">
                            <td colspan="7">
                                <div class="email-lists-empty">
                                    <p class="email-lists-empty-title">No verification batches yet.</p>
                                    <p class="email-lists-empty-desc">Upload a CSV or TXT file with one email per line to
                                        get started.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-data-table>
    </div>
@endsection
