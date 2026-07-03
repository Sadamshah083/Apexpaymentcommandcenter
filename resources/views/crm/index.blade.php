@extends('layouts.admin')

@section('title', 'Business CRM')

@section('content')
    <div class="app-page crm-page">
        <div class="app-page-header flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div>
                <h1 class="app-page-title">Business CRM</h1>
                <p class="app-page-subtitle">Upload CSV leads — AI enriches owner, phone, email, payment processor, and POS
                    data.</p>
            </div>
            <a href="{{ route('admin.crm.create') }}" class="app-btn app-btn-primary shrink-0">Upload CSV</a>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 app-stat-grid crm-stats">
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Campaigns</p>
                <p class="app-kpi-value crm-kpi--primary">{{ $stats['total_campaigns'] }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Total Leads</p>
                <p class="app-kpi-value">{{ number_format($stats['total_leads']) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Enriched</p>
                <p class="app-kpi-value crm-kpi--good">{{ number_format($stats['completed_leads']) }}</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="app-kpi-label">Processing</p>
                <p class="app-kpi-value crm-kpi--warn">{{ $stats['processing_campaigns'] }}</p>
            </div>
        </div>

        <x-data-table :paginator="$campaigns" min-width="880px" class="crm-data-table">
            <x-slot:header>
                <div class="crm-table-header">
                    <h2 class="app-data-table-title">Campaigns</h2>
                    <a href="{{ route('admin.business-research.index') }}" class="crm-table-link">Single business lookup</a>
                </div>
            </x-slot:header>
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>File</th>
                        <th>Leads</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($campaigns as $campaign)
                        @php
                            $statusClass = match ($campaign->status) {
                                'completed' => 'app-badge app-badge-success',
                                'processing', 'importing' => 'app-badge app-badge-warning',
                                'failed' => 'app-badge app-badge-danger',
                                default => 'app-badge app-badge-muted',
                            };
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.crm.show', $campaign) }}" class="crm-campaign-link">
                                    {{ $campaign->name }}
                                </a>
                            </td>
                            <td class="crm-file">{{ $campaign->original_filename ?? '—' }}</td>
                            <td class="crm-leads">{{ $campaign->total_leads }}</td>
                            <td>
                                <div class="crm-progress">
                                    <div class="crm-progress-track">
                                        <div class="crm-progress-bar progress-bar-live"
                                            style="width: {{ $campaign->progressPercent() }}%"></div>
                                    </div>
                                    <span class="crm-progress-label">{{ $campaign->progressPercent() }}%</span>
                                </div>
                            </td>
                            <td>
                                <span class="{{ $statusClass }}">{{ $campaign->status }}</span>
                            </td>
                            <td class="crm-date">{{ $campaign->created_at->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr class="crm-empty-row">
                            <td colspan="6">
                                <div class="crm-empty">
                                    <p class="crm-empty-title">No campaigns yet.</p>
                                    <p class="crm-empty-desc">
                                        <a href="{{ route('admin.crm.create') }}" class="crm-empty-link">Upload a CSV</a>
                                        to start.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-data-table>
    </div>
@endsection
