@extends('layouts.admin')

@section('title', 'Lead tags')

@section('content')
<div class="app-page space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <h1 class="app-page-title">Lead tags</h1>
            <p class="app-page-subtitle">Trace leads across imports. Select tags to batch-enrich or assign setters from any file.</p>
        </div>
        <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-secondary app-btn-sm">New import</a>
    </div>

    @if($tags->isEmpty())
        <div class="app-card app-card-padded app-empty-state">
            <p class="app-empty-state-title">No tags yet</p>
            <p class="app-empty-state-desc">Tags are applied during import on the column mapping step. Import a file and add tags like <em>texas</em> or <em>june-2026</em>.</p>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary mt-4">Import leads</a>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($tags as $tag)
                <a href="{{ route('admin.lead-tags.show', ['tag_ids' => [$tag->id]]) }}" class="app-card app-card-padded hover:border-indigo-300 transition-colors group">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <span class="inline-block w-2 h-8 rounded-full mr-2 align-middle" style="background: {{ $tag->color }}"></span>
                            <span class="font-bold text-zinc-900 group-hover:text-indigo-700">{{ $tag->name }}</span>
                        </div>
                        <span class="text-xs font-semibold text-zinc-400">{{ number_format($tag->leads_count) }} leads</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-2 mt-4 text-xs">
                        <div>
                            <dt class="text-zinc-400">Imported</dt>
                            <dd class="font-semibold text-zinc-700">{{ number_format($tag->imported_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">Enriched</dt>
                            <dd class="font-semibold text-zinc-700">{{ number_format($tag->enriched_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">Assigned</dt>
                            <dd class="font-semibold text-emerald-700">{{ number_format($tag->assigned_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">Failed</dt>
                            <dd class="font-semibold text-rose-600">{{ number_format($tag->failed_count) }}</dd>
                        </div>
                    </dl>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
