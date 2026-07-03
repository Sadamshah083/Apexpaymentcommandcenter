@extends('layouts.admin')

@section('title', 'Campaigns')

@section('content')
<div class="app-page campaigns-page">
    <div class="app-page-header flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="app-page-title">Campaigns</h1>
            <p class="app-page-subtitle">Group imports and leads under campaigns. Batch enrich, assign, and report by campaign.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.dashboard') }}" class="app-btn app-btn-secondary">Command Center</a>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary">Import file</a>
        </div>
    </div>

    <div class="app-card app-card-padded mb-6">
        <form method="POST" action="{{ route('admin.campaigns.store') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <input type="text" name="name" required maxlength="100" placeholder="New campaign name" class="app-input flex-1">
            <button type="submit" class="app-btn app-btn-primary shrink-0">Create campaign</button>
        </form>
    </div>

    @if ($campaigns->isEmpty())
        <div class="app-card app-card-padded app-empty-state">
            <p class="app-empty-state-title">No campaigns yet</p>
            <p class="app-empty-state-desc">Create a campaign, then import a file and select it during upload.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            @foreach ($campaigns as $campaign)
                <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaign-card">
                    <div class="campaign-card-head">
                        <span class="campaign-card-name">{{ $campaign->name }}</span>
                        <span class="campaign-card-count">{{ number_format($campaign->leads_count) }} leads</span>
                    </div>
                    <dl class="campaign-card-stats">
                        <div><dt>Imports</dt><dd>{{ $campaign->imports_count }}</dd></div>
                        <div><dt>Enriched</dt><dd>{{ number_format($campaign->enriched_count) }}</dd></div>
                        <div><dt>Assigned</dt><dd>{{ number_format($campaign->assigned_count) }}</dd></div>
                        <div><dt>Failed</dt><dd>{{ number_format($campaign->failed_count) }}</dd></div>
                    </dl>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
