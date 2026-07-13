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
            <x-import-file-link />
        </div>
    </div>

    <div class="app-card app-card-padded mb-6 campaigns-create-bar">
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
        <div class="app-data-table campaigns-table-wrap">
            <div class="app-data-table-header">
                <h2 class="app-data-table-title">All campaigns</h2>
            </div>
            <div class="app-table-wrap" data-min-width="880px">
                <table class="campaigns-table">
                    <thead>
                        <tr>
                            <th class="col-name">Campaign</th>
                            <th class="col-leads">Leads</th>
                            <th class="col-imports">Imports</th>
                            <th class="col-enriched">Enriched</th>
                            <th class="col-assigned">Assigned</th>
                            <th class="col-failed">Failed</th>
                            <th class="col-action text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $campaign)
                            <tr>
                                <td class="col-name">
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}" class="campaigns-table-name">
                                        {{ $campaign->name }}
                                    </a>
                                </td>
                                <td class="col-leads">{{ number_format($campaign->leads_count) }}</td>
                                <td class="col-imports">{{ number_format($campaign->imports_count) }}</td>
                                <td class="col-enriched">{{ number_format($campaign->enriched_count) }}</td>
                                <td class="col-assigned">{{ number_format($campaign->assigned_count) }}</td>
                                <td class="col-failed">{{ number_format($campaign->failed_count) }}</td>
                                <td class="col-action text-right">
                                    <a href="{{ route('admin.campaigns.show', $campaign) }}" class="app-btn app-btn-secondary app-btn-sm">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
