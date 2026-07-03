@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', request()->is('admin*') ? 'Enrichment and import files' : 'My Lead Pool')

@section('content')
@if(request()->is('admin*'))
    <div id="workspace-sync-page" data-sync-scope="list" class="hidden" aria-hidden="true"></div>
@endif
<div class="app-page import-workflows-page space-y-5">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            @if(request()->is('admin*'))
                <h1 class="app-page-title">Enrichment and import files</h1>
                <p class="app-page-subtitle">Upload CSV files, run AI enrichment, assign to your team, and review all lead data per import.</p>
            @else
                <h1 class="app-page-title">My Lead Pool</h1>
                <p class="app-page-subtitle">Work assigned leads and log activity toward daily goals.</p>
            @endif
        </div>
        @if(request()->is('admin*'))
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary w-full sm:w-auto shrink-0">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Import file
            </a>
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
            @if(isset($enrichmentStatus))
                @include('workflows.partials.enrichment-status', ['status' => $enrichmentStatus])
            @endif

            <div class="app-card app-card-padded import-workflows-section">
                <div class="flex flex-col gap-3 mb-4">
                    <div>
                        <h2 class="app-section-title">Recent imports</h2>
                        <p class="app-section-desc">Manage imports, view enriched data, assign to your team, or delete from the database.</p>
                    </div>
                    <p class="import-table-scroll-hint">
                        Swipe the table left and right on smaller screens to see all columns.
                    </p>
                </div>

                @if($workflows->isEmpty())
                    <div class="app-empty-state">
                        <div class="app-empty-state-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <p class="app-empty-state-title">No imports yet</p>
                        <p class="app-empty-state-desc">Upload a CSV or Excel file to start enrichment.</p>
                        <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary app-btn-sm mt-4">Import file</a>
                    </div>
                @else
                    <x-data-table :paginator="$workflows" min-width="1080px" class="import-workflows-data-table">
                        <table class="import-workflows-table">
                            <thead>
                                <tr>
                                    <th class="col-import-name">Import name</th>
                                    <th class="col-file">File</th>
                                    <th class="col-status">Status</th>
                                    <th class="col-progress min-w-[148px]">Progress</th>
                                    <th class="col-total">Total</th>
                                    <th class="col-enriched">Enriched</th>
                                    <th class="col-assigned">Assigned</th>
                                    <th class="col-remaining">Remaining</th>
                                    <th class="col-assign">Assign</th>
                                    <th class="col-actions text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="workspace-sync-workflows" data-sync-mode="cells" data-expected-cols="10" data-admin-workflows-table="1">
                                @foreach($workflows as $wf)
                                    @php
                                        $assignedCount = (int) ($wf->assigned_leads_count ?? 0);
                                        $enrichedCount = (int) ($wf->enriched_leads ?? 0);
                                        $remaining = max(0, (int) ($wf->ready_to_assign_count ?? 0));
                                        if ($remaining === 0 && $enrichedCount > $assignedCount && ! in_array($wf->status, ['mapping', 'failed'], true)) {
                                            $remaining = $enrichedCount - $assignedCount;
                                        }
                                        $canAssign = $remaining > 0 && ! in_array($wf->status, ['mapping', 'failed'], true);
                                        $totalLeads = (int) ($wf->total_leads ?? 0);
                                        $attempted = $enrichedCount + (int) ($wf->failed_leads ?? 0);
                                        $progressPct = $totalLeads > 0 ? min(100, (int) round(($attempted / $totalLeads) * 100)) : 0;
                                        $isActive = in_array($wf->status, ['pending', 'extracting', 'paused'], true);
                                    @endphp
                                    <tr data-workflow-id="{{ $wf->id }}" data-workflow-status="{{ $wf->status }}">
                                        <td data-label="Import name" class="col-import-name">
                                            <div class="import-workflow-name">{{ $wf->name }}</div>
                                            @if($wf->leadList)
                                                <div class="import-workflow-meta">List: {{ $wf->leadList->name }}</div>
                                            @endif
                                        </td>
                                        <td data-label="File" class="col-file import-workflow-file" title="{{ $wf->original_filename }}">{{ $wf->original_filename }}</td>
                                        <td data-label="Status" class="col-status"><x-workflow-status-pill :status="$wf->status" /></td>
                                        <td data-label="Progress" class="col-progress min-w-[148px]">
                                            @if($totalLeads === 0 && $wf->status === 'mapping')
                                                <span class="text-xs text-zinc-400">Awaiting setup</span>
                                            @else
                                                <div class="space-y-1">
                                                    <div class="app-progress-track h-1.5">
                                                        <div class="app-progress-fill {{ $isActive ? '' : 'bg-emerald-500' }}" style="width: {{ $progressPct }}%"></div>
                                                    </div>
                                                    <p class="import-workflow-progress-text">
                                                        {{ number_format($attempted) }} / {{ number_format($totalLeads) }} enriched
                                                    </p>
                                                </div>
                                            @endif
                                        </td>
                                        <td data-label="Total" class="col-total import-workflow-stat">{{ number_format($totalLeads) }}</td>
                                        <td data-label="Enriched" class="col-enriched import-workflow-stat">{{ number_format($enrichedCount) }}</td>
                                        <td data-label="Assigned" class="col-assigned import-workflow-stat import-workflow-stat-success">{{ number_format($wf->assigned_leads_count ?? 0) }}</td>
                                        <td data-label="Remaining" class="col-remaining import-workflow-stat import-workflow-stat-warning">{{ number_format($remaining) }}</td>
                                        <td data-label="Assign" class="col-assign">
                                            @if($canAssign)
                                                <button
                                                    type="button"
                                                    class="app-btn app-btn-primary app-btn-sm import-assign-btn"
                                                    data-import-assign-open
                                                    data-workflow-id="{{ $wf->id }}"
                                                    data-workflow-name="{{ $wf->name }}"
                                                    data-workflow-total="{{ $totalLeads }}"
                                                    data-workflow-enriched="{{ $enrichedCount }}"
                                                    data-workflow-assigned="{{ $wf->assigned_leads_count ?? 0 }}"
                                                    data-workflow-remaining="{{ $remaining }}"
                                                >Assign</button>
                                            @else
                                                <span class="import-assign-empty">&mdash;</span>
                                            @endif
                                        </td>
                                        <td data-label="Actions" class="col-actions text-right">
                                            @include('workflows.partials.import-row-actions', ['wf' => $wf, 'totalLeads' => $totalLeads])
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-data-table>
                @endif
            </div>
        </div>

        @include('workflows.partials.import-modals', [
            'teamLeads' => $teamLeads ?? collect(),
        ])
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
                                        @include('partials.lead-tag-chips', ['tags' => $lead->tags, 'list' => $lead->leadList, 'compact' => true])
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
