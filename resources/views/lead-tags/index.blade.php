@extends('layouts.admin')

@section('title', 'Lead tags')

@section('content')
    <div class="app-page lead-tags-page">
        <div class="app-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="app-page-title">Lead tags</h1>
                <p class="app-page-subtitle">Trace leads across imports. Select tags to batch-enrich or assign setters from
                    any file.</p>
            </div>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary shrink-0">New import</a>
        </div>

        @if ($tags->isEmpty())
            <div class="app-card app-card-padded lead-tags-empty">
                <p class="lead-tags-empty-title">No tags yet</p>
                <p class="lead-tags-empty-desc">Tags are applied during import on the column mapping step. Import a file and
                    add tags like <em>texas</em> or <em>june-2026</em>.</p>
                <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary lead-tags-empty-action">Import leads</a>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 lead-tags-grid">
                @foreach ($tags as $tag)
                    <a href="{{ route('admin.lead-tags.show', ['tag_ids' => [$tag->id]]) }}"
                        class="app-card app-card-padded lead-tags-card group">
                        <div class="lead-tags-card-head">
                            <div class="lead-tags-card-name">
                                <span class="lead-tags-swatch" style="background: {{ $tag->color }}"></span>
                                <span class="lead-tags-name">{{ $tag->name }}</span>
                            </div>
                            <span class="lead-tags-count">{{ number_format($tag->leads_count) }} leads</span>
                        </div>
                        <dl class="lead-tags-stats">
                            <div>
                                <dt>Imported</dt>
                                <dd>{{ number_format($tag->imported_count) }}</dd>
                            </div>
                            <div>
                                <dt>Enriched</dt>
                                <dd>{{ number_format($tag->enriched_count) }}</dd>
                            </div>
                            <div>
                                <dt>Assigned</dt>
                                <dd class="is-good">{{ number_format($tag->assigned_count) }}</dd>
                            </div>
                            <div>
                                <dt>Failed</dt>
                                <dd class="is-bad">{{ number_format($tag->failed_count) }}</dd>
                            </div>
                        </dl>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
@endsection
