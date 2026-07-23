@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', request()->is('admin*')
    ? ((!empty($assignedLeadsView) || request()->routeIs('admin.assigned-leads') || request('view') === 'assigned') ? 'Assigned Leads' : 'Imported Leads')
    : 'My Lead Pool')

@section('content')
@php
    $isAssignedLeadsView = !empty($assignedLeadsView) || request()->routeIs('admin.assigned-leads') || request('view') === 'assigned';
@endphp
<div class="app-page import-workflows-page space-y-5 {{ $isAssignedLeadsView ? 'import-workflows-page--assigned' : '' }}" data-import-hub>
    <div class="import-leads-page-head flex flex-row items-center justify-between gap-3">
        <div class="min-w-0 flex-1">
            @if(request()->is('admin*'))
                @if ($isAssignedLeadsView)
                    <h1 class="app-page-title">Assigned Leads</h1>
                @else
                    <h1 class="app-page-title">Imported Leads</h1>
                @endif
            @else
                <h1 class="app-page-title">My Lead Pool</h1>
            @endif
        </div>
        <div class="import-leads-page-head__actions shrink-0 flex flex-wrap items-center justify-end gap-2">
            @if(request()->is('admin*') && $isAssignedLeadsView)
                @php
                    $headerSelectedFiles = collect(request()->input('workflow_ids', []))
                        ->map(fn ($id) => (string) $id)
                        ->filter()
                        ->values();
                    if ($headerSelectedFiles->isEmpty() && filled(request('workflow_id'))) {
                        $headerSelectedFiles = collect([(string) request('workflow_id')]);
                    }
                    $headerFileCount = $headerSelectedFiles->count();
                @endphp
                <button type="button"
                    class="app-btn app-btn-secondary app-btn-sm import-leads-page-head__files-btn"
                    data-uploaded-files-open
                    aria-haspopup="dialog"
                    aria-controls="uploaded-files-modal">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" />
                    </svg>
                    <span>Uploaded files</span>
                    <span class="uploaded-files-btn-count" data-uploaded-files-count {{ $headerFileCount === 0 ? 'hidden' : '' }}>
                        {{ $headerFileCount > 0 ? $headerFileCount : '' }}
                    </span>
                </button>
            @elseif(request()->is('admin*') && ! $isAssignedLeadsView)
                @if (isset($enrichmentStatus))
                    <button type="button"
                        class="app-btn app-btn-secondary app-btn-sm"
                        data-enrichment-status-open
                        aria-haspopup="dialog"
                        aria-controls="enrichment-status-modal">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span>AI enrichment</span>
                    </button>
                @endif
                <x-import-file-link class="import-leads-page-head__btn" />
            @endif
        </div>
    </div>

    @if(request()->is('admin*') && ! $isAssignedLeadsView && isset($enrichmentStatus))
        <div id="enrichment-status-modal" class="uploaded-files-modal enrichment-status-modal" hidden aria-hidden="true"
            role="dialog" aria-labelledby="enrichment-status-modal-title" aria-modal="true">
            <div class="uploaded-files-modal__backdrop" data-enrichment-status-close></div>
            <div class="uploaded-files-modal__panel enrichment-status-modal__panel" role="document">
                <div class="uploaded-files-modal__header">
                    <div>
                        <h3 id="enrichment-status-modal-title" class="uploaded-files-modal__title">AI enrichment</h3>
                        <p class="uploaded-files-modal__desc">Provider status, pipeline model, and balance.</p>
                    </div>
                    <button type="button" class="app-modal-close" data-enrichment-status-close aria-label="Close">&times;</button>
                </div>
                <div class="enrichment-status-modal__body">
                    @include('workflows.partials.enrichment-status', ['status' => $enrichmentStatus, 'embedded' => true])
                </div>
            </div>
        </div>
        @push('scripts')
        <script>
        (() => {
            const modal = document.getElementById('enrichment-status-modal');
            if (!modal || modal.dataset.bound === '1') return;
            modal.dataset.bound = '1';
            const open = () => {
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('uploaded-files-modal-open');
            };
            const close = () => {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('uploaded-files-modal-open');
            };
            document.querySelectorAll('[data-enrichment-status-open]').forEach((btn) => {
                btn.addEventListener('click', open);
            });
            modal.querySelectorAll('[data-enrichment-status-close]').forEach((el) => {
                el.addEventListener('click', close);
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) close();
            });
        })();
        </script>
        @endpush
    @endif

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
            @if ($isAssignedLeadsView)
                @include('admin.dashboard.partials.imports-panel', ['assignedLeadsOnly' => true])
            @else
                @include('admin.dashboard.partials.imports-panel', ['assignedLeadsOnly' => false])
            @endif
        </div>
    @else
        <div class="app-card app-card-padded">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h2 class="app-section-title">Assigned leads</h2>
                    <p class="app-section-desc">Update stage and log outreach.</p>
                </div>

                <form method="GET" action="{{ route('portal.dashboard') }}" class="active-leads-filters flex flex-wrap items-end gap-2.5">
                    <div class="active-leads-filters__field active-leads-filters__field--search">
                        <label class="active-leads-filters__label" for="portal-leads-search">Search</label>
                        <div class="app-search-wrap">
                            <svg class="app-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input id="portal-leads-search" type="text" name="search" value="{{ request('search') }}" placeholder="Search business, owner…" class="app-input">
                        </div>
                    </div>
                    <div class="active-leads-filters__field">
                        <label class="active-leads-filters__label" for="portal-leads-phase">Phase</label>
                        <select id="portal-leads-phase" name="phase" onchange="this.form.submit()" class="app-input js-pretty-select" data-pretty-select>
                            <option value="">All phases</option>
                            @foreach($pipelinePhases ?? [] as $value => $label)
                                <option value="{{ $value }}" {{ request('phase') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Filter</button>
                    @if(request()->anyFilled(['search', 'phase']))
                        <a href="{{ route('portal.dashboard') }}" class="app-btn app-btn-ghost app-btn-sm" title="Clear filters">Clear</a>
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
