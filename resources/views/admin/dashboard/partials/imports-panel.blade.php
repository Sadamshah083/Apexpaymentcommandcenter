@if (isset($importsWorkflows))
@php
    $importsIndexRoute = request()->routeIs('admin.workflows.*')
        ? route('admin.workflows.index')
        : route('admin.dashboard');
@endphp
<div id="imports" class="admin-dash-section {{ ($activeSection ?? '') === 'imports' ? 'admin-dash-section--focus' : '' }}">
    <div id="workspace-sync-page" data-sync-scope="list" class="hidden" aria-hidden="true"></div>

    <div class="admin-dash-card">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
            <div>
                <h3 class="admin-dash-section-title">Import leads</h3>
                <p class="admin-dash-section-desc">Upload files under campaigns, enrich, and assign to your team.</p>
            </div>
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-success app-btn-sm shrink-0" data-turbo-preload data-import-file-nav>Import leads</a>
        </div>

        @if (isset($enrichmentStatus))
            @include('workflows.partials.enrichment-status', ['status' => $enrichmentStatus, 'embedded' => true])
        @endif

        @if ($importsWorkflows->isEmpty())
            <div class="app-empty-state">
                <p class="app-empty-state-title">No imports yet</p>
                <p class="app-empty-state-desc">Create a campaign and upload your first file.</p>
                <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-success app-btn-sm mt-4" data-turbo-preload data-import-file-nav>Import leads</a>
            </div>
        @else
            <x-data-table :paginator="$importsWorkflows" min-width="1080px" class="import-workflows-data-table">
                <table class="import-workflows-table">
                    <thead>
                        <tr>
                            <th>Import / Campaign</th>
                            <th>File</th>
                            <th>Status</th>
                            <th class="min-w-[148px]">Progress</th>
                            <th>Total</th>
                            <th>Enriched</th>
                            <th>Assigned</th>
                            <th>Unassigned</th>
                            <th>Assign</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-workflows" data-sync-mode="cells" data-expected-cols="10" data-admin-workflows-table="1" data-campaign-show-base="{{ url('admin/campaigns') }}">
                        @foreach ($importsWorkflows as $wf)
                            @php
                                $assignedCount = (int) ($wf->assigned_leads_count ?? 0);
                                $enrichedCount = (int) ($wf->enriched_leads_count ?? $wf->enriched_leads ?? 0);
                                $unassigned = max(0, (int) ($wf->ready_to_assign_count ?? 0));
                                $canAssign = $unassigned > 0 && ! in_array($wf->status, ['mapping', 'failed'], true);
                                $totalLeads = (int) ($wf->total_leads ?? 0);
                                $attempted = $enrichedCount + (int) ($wf->failed_leads ?? 0);
                                $progressPct = $totalLeads > 0 ? min(100, (int) round(($attempted / $totalLeads) * 100)) : 0;
                                $isActive = in_array($wf->status, ['pending', 'extracting', 'paused'], true);
                            @endphp
                            <tr data-workflow-id="{{ $wf->id }}" data-workflow-status="{{ $wf->status }}">
                                <td>
                                    <div class="import-workflow-name">{{ $wf->name }}</div>
                                    @if ($wf->campaign)
                                        <a href="{{ route('admin.campaigns.show', $wf->campaign_id) }}" class="campaign-chip campaign-chip--sm mt-1">{{ $wf->campaign->name }}</a>
                                    @endif
                                </td>
                                <td class="import-workflow-file" title="{{ $wf->original_filename }}">{{ $wf->original_filename }}</td>
                                <td><x-workflow-status-pill :status="$wf->status" /></td>
                                <td class="min-w-[148px]">
                                    @if ($totalLeads === 0 && $wf->status === 'mapping')
                                        <span class="text-xs text-zinc-400">Awaiting setup</span>
                                    @else
                                        <div class="space-y-1">
                                            <div class="app-progress-track h-1.5">
                                                <div class="app-progress-fill {{ $isActive ? '' : 'bg-emerald-500' }}" style="width: {{ $progressPct }}%"></div>
                                            </div>
                                            <p class="import-workflow-progress-text">{{ number_format($attempted) }} / {{ number_format($totalLeads) }}</p>
                                        </div>
                                    @endif
                                </td>
                                <td class="import-workflow-stat">{{ number_format($totalLeads) }}</td>
                                <td class="import-workflow-stat">{{ number_format($enrichedCount) }}</td>
                                <td class="import-workflow-stat import-workflow-stat-success">{{ number_format($assignedCount) }}</td>
                                <td class="import-workflow-stat import-workflow-stat-warning">
                                    @if ($unassigned > 0)
                                        <a href="{{ route('admin.workflows.show', ['workflow' => $wf->id, 'pool' => 'unassigned']) }}"
                                            class="import-workflow-unassigned-link"
                                            title="View and review unassigned leads">{{ number_format($unassigned) }}</a>
                                    @else
                                        {{ number_format($unassigned) }}
                                    @endif
                                </td>
                                <td>
                                    @if ($canAssign)
                                        <button type="button" class="app-btn app-btn-success app-btn-sm import-assign-btn"
                                            data-import-assign-open data-workflow-id="{{ $wf->id }}"
                                            data-workflow-name="{{ $wf->name }}" data-workflow-total="{{ $totalLeads }}"
                                            data-workflow-enriched="{{ $enrichedCount }}"
                                            data-workflow-assigned="{{ $assignedCount }}"
                                            data-workflow-remaining="{{ $unassigned }}">Assign</button>
                                    @else
                                        <span class="import-assign-empty">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @include('workflows.partials.import-row-actions', ['wf' => $wf, 'totalLeads' => $totalLeads])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-data-table>
        @endif
    </div>

    @if (isset($leads) && $leads instanceof \Illuminate\Contracts\Pagination\Paginator)
        <div class="admin-dash-card mt-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <div>
                    <h3 class="admin-dash-section-title">Active leads</h3>
                    <p class="admin-dash-section-desc">Search and filter leads across all imports.</p>
                </div>
                <form method="GET" action="{{ $importsIndexRoute }}" class="flex flex-wrap items-center gap-2">
                    @unless (request()->routeIs('admin.workflows.*'))
                        <input type="hidden" name="section" value="imports">
                    @endunless
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Search leads…" class="app-input app-input-sm">
                    <select name="phase" class="app-input app-input-sm" onchange="this.form.submit()">
                        <option value="">All phases</option>
                        @foreach ($pipelinePhases ?? [] as $value => $label)
                            <option value="{{ $value }}" @selected(request('phase') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Filter</button>
                </form>
            </div>
            <x-data-table :paginator="$leads" min-width="960px">
                <table>
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Campaign</th>
                            <th>Contact</th>
                            <th>Stage</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-leads-body" data-sync-mode="patch" data-sync-leads-format="command-center" data-campaign-show-base="{{ url('admin/campaigns') }}" data-lead-show-base="{{ url('admin/leads') }}">
                        @foreach ($leads as $lead)
                            @php $display = \App\Support\LeadContactDisplay::for($lead); @endphp
                            <tr data-lead-id="{{ $lead->id }}">
                                <td>
                                    <div class="font-bold text-zinc-900">{{ $lead->business_name }}</div>
                                    <div class="text-xs text-zinc-400">{{ $lead->city }}, {{ $lead->state }}</div>
                                </td>
                                <td>
                                    @if ($lead->campaign)
                                        <a href="{{ route('admin.campaigns.show', $lead->campaign_id) }}" class="campaign-chip campaign-chip--sm">{{ $lead->campaign->name }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-sm text-zinc-600">{{ \App\Support\LeadContactDisplay::cell($display['phone']) ?: '—' }}</td>
                                <td><span class="app-badge app-badge-muted">{{ \App\Support\SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</span></td>
                                <td class="text-right">
                                    <a href="{{ \App\Support\LeadRoute::show($lead, true) }}" class="app-btn app-btn-secondary app-btn-sm">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-data-table>
        </div>
    @endif

    @include('workflows.partials.import-modals', [
        'setterTeamLeads' => $setterTeamLeads ?? $team ?? collect(),
        'activeSetters' => $activeSetters ?? collect(),
        'setterTeamMemberMap' => $setterTeamMemberMap ?? [],
        'activeSetterCount' => $activeSetterCount ?? 0,
    ])
</div>
@endif
