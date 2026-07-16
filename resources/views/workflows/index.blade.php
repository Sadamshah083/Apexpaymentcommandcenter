@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', request()->is('admin*') ? 'Import leads' : 'My Lead Pool')

@section('content')
<div class="app-page import-workflows-page space-y-5" data-import-hub>
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            @if(request()->is('admin*'))
                <h1 class="app-page-title">Import leads</h1>
                <p class="app-page-subtitle">Upload CSV files, track workflow performance, manage campaigns, and assign enriched leads.</p>
            @else
                <h1 class="app-page-title">My Lead Pool</h1>
                <p class="app-page-subtitle">Work assigned leads and log activity toward daily goals.</p>
            @endif
        </div>
        @if(request()->is('admin*'))
            <x-import-file-link />
        @endif
    </div>

    @if(isset($dailyMetrics))
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 app-stat-grid">
            @foreach(['dials' => 'Dials', 'conversations' => 'Conversations', 'decision_maker_contacts' => 'DM Contacts', 'discoveries' => 'Discoveries'] as $key => $label)
                @php $m = $dailyMetrics[$key]; @endphp
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">{{ $label }} Today</p>
                    <p class="app-kpi-value">
                        <span id="workspace-sync-metric-{{ $key }}-actual">{{ $m['actual'] }}</span><span class="text-base font-semibold text-zinc-400"> / <span id="workspace-sync-metric-{{ $key }}-target">{{ $m['target'] }}</span></span>
                    </p>
                    <div class="app-progress-track mt-2">
                        <div id="workspace-sync-metric-{{ $key }}-bar" class="app-progress-fill" style="width: {{ $m['pct'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="text-right">
            <a href="{{ route('portal.performance') }}" class="app-link text-sm">Full performance dashboard</a>
        </div>
    @endif

    @if(request()->is('admin*'))
        <div class="space-y-6">
            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-4">Data files &amp; workflow performance</h2>
                <div class="admin-dash-table-wrap">
                    <table class="admin-dash-table">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Imported At</th>
                                <th class="text-right">Total Leads</th>
                                <th class="text-right">Enriched Success</th>
                                <th class="text-right">Failed</th>
                                <th class="text-right">Closed Won</th>
                                <th class="text-right">Enrichment %</th>
                                <th class="text-right">Close %</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse (($workflowSummaries ?? []) as $wf)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.workflows.show', $wf['id']) }}" class="admin-dash-table-link">{{ $wf['name'] }}</a>
                                        <span class="admin-dash-table-meta">{{ $wf['filename'] }}</span>
                                    </td>
                                    <td>{{ $wf['created_at'] }}</td>
                                    <td class="text-right"><span class="admin-dash-table-num">{{ $wf['total_leads'] }}</span></td>
                                    <td class="text-right"><span class="admin-dash-table-num is-success">{{ $wf['enriched_leads'] }}</span></td>
                                    <td class="text-right"><span class="admin-dash-table-num is-danger">{{ $wf['failed_leads'] }}</span></td>
                                    <td class="text-right"><span class="admin-dash-table-num is-success">{{ $wf['closed_deals'] }}</span></td>
                                    <td class="text-right"><span class="admin-dash-badge is-success">{{ $wf['enrichment_rate'] }}%</span></td>
                                    <td class="text-right"><span class="admin-dash-badge is-success">{{ $wf['close_rate'] }}%</span></td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.workflows.show', $wf['id']) }}" class="app-btn app-btn-secondary app-btn-sm">View details</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="admin-dash-empty">No workflow files uploaded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (!empty($workflowSummariesPaginator) && $workflowSummariesPaginator->hasPages())
                    <x-pagination :paginator="$workflowSummariesPaginator" class="mt-4" />
                @endif
            </div>

            @include('admin.dashboard.partials.campaigns-panel')
            @include('admin.dashboard.partials.imports-panel')
        </div>
    @else
        <div class="app-card app-card-padded">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h2 class="app-section-title">Assigned leads</h2>
                    <p class="app-section-desc">Update stage and log outreach.</p>
                </div>

                <form method="GET" action="{{ route('portal.dashboard') }}" class="flex flex-wrap items-center gap-2">
                    <div class="app-search-wrap">
                        <svg class="app-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search business, owner…" class="app-input">
                    </div>
                    <select name="phase" onchange="this.form.submit()" class="app-input !w-auto">
                        <option value="">All phases</option>
                        @foreach($pipelinePhases ?? [] as $value => $label)
                            <option value="{{ $value }}" {{ request('phase') === $value ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if(request()->anyFilled(['search', 'phase']))
                        <a href="{{ route('portal.dashboard') }}" class="app-btn app-btn-secondary app-btn-sm" title="Clear filters">Clear</a>
                    @endif
                </form>
            </div>

            @if($leads->isEmpty())
                <div class="app-empty-state">
                    <div class="app-empty-state-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <p class="app-empty-state-title">No leads found</p>
                    <p class="app-empty-state-desc">Adjust your filters or wait for new assignments.</p>
                </div>
            @else
                <x-data-table :paginator="$leads" min-width="960px">
                    <table>
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Owner</th>
                                <th>Email</th>
                                <th>Social Media</th>
                                <th>Contact</th>
                                <th>Processor</th>
                                <th>Stage</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="workspace-sync-leads-body" data-sync-mode="{{ request()->anyFilled(['search', 'stage', 'tier']) || $leads->currentPage() > 1 ? 'patch' : 'replace' }}">
                            @foreach($leads as $lead)
                                @php($display = \App\Support\LeadContactDisplay::for($lead))
                                <tr data-lead-id="{{ $lead->id }}">
                                    <td>
                                        <div class="font-bold text-zinc-900">{{ $lead->business_name }}</div>
                                        @if($lead->address)
                                            <div class="text-xs text-zinc-500 mt-0.5">{{ $lead->address }}</div>
                                        @endif
                                        <div class="text-[10px] text-zinc-400 font-normal mt-0.5">
                                            {{ $lead->city }}, {{ $lead->state }}
                                        </div>
                                        @include('partials.campaign-chip', ['campaign' => $lead->campaign, 'compact' => true])
                                    </td>
                                    <td class="font-medium text-zinc-600">{{ \App\Support\LeadContactDisplay::cell($display['owner']) ?: '—' }}</td>
                                    <td class="text-sm text-zinc-600">{{ \App\Support\LeadContactDisplay::cell($display['email']) ?: '—' }}</td>
                                    <td class="text-sm text-zinc-600">{{ \App\Support\LeadContactDisplay::cell($display['social_media']) ?: '—' }}</td>
                                    <td class="text-sm text-zinc-600">{{ \App\Support\LeadContactDisplay::cell($display['phone']) ?: '—' }}</td>
                                    <td class="text-sm text-zinc-600">
                                        @if(\App\Support\LeadContactDisplay::cell($display['processor']))
                                            <span class="app-badge app-badge-info">{{ \App\Support\LeadContactDisplay::cell($display['processor']) }}</span>
                                        @else
                                            <span class="text-zinc-400">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="app-badge app-badge-muted">{{ \App\Support\SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</span>
                                        @if($lead->setter_status)
                                            <div class="text-[10px] text-zinc-400 mt-1">{{ \App\Support\SalesOps::setterStatusLabel($lead->setter_status) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right">
                                        <a href="{{ \App\Support\LeadRoute::show($lead, false) }}" class="app-btn app-btn-secondary app-btn-sm">Details</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-data-table>
            @endif
        </div>
    @endif
</div>
@endsection

@push('head')
    <link rel="prefetch" href="{{ route('admin.workflows.create') }}" as="document" data-import-prefetch>
@endpush
