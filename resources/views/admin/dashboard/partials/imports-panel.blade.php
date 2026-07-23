@if (isset($importsWorkflows) || !empty($assignedLeadsOnly))
@php
    $isAssignedLeadsPage = request()->routeIs('admin.assigned-leads');
    $assignedLeadsOnly = !empty($assignedLeadsOnly) || !empty($assignedLeadsView) || $isAssignedLeadsPage || request('view') === 'assigned';
    $importsIndexRoute = $isAssignedLeadsPage
        ? route('admin.assigned-leads')
        : (request()->routeIs('admin.workflows.*')
            ? route('admin.workflows.index')
            : route('admin.dashboard'));
@endphp
<div id="imports" class="admin-dash-section {{ ($activeSection ?? '') === 'imports' ? 'admin-dash-section--focus' : '' }}">
    <div id="workspace-sync-page" data-sync-scope="list" data-use-poll="1" data-sync-poll-ms="20000" class="hidden" aria-hidden="true"></div>

    @unless ($assignedLeadsOnly)
    <div class="admin-dash-card import-workflows-panel-card">
        @if ($importsWorkflows->isEmpty())
            <div class="app-empty-state">
                <p class="app-empty-state-title">No imports yet</p>
                <p class="app-empty-state-desc">Create a campaign and upload your first file.</p>
                <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-success app-btn-sm mt-4" data-turbo-preload data-import-file-nav>Import leads</a>
            </div>
        @else
            <div class="import-workflows-table-scroll" data-imports-table-scroll>
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
                            <th class="col-assigned">Assigned / Agents</th>
                            <th>Dispositions</th>
                            <th>Ready</th>
                            <th>Assign</th>
                            <th>Agents</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-workflows" data-sync-mode="cells" data-expected-cols="12" data-admin-workflows-table="1" data-campaign-show-base="{{ url('admin/campaigns') }}">
                        @foreach ($importsWorkflows as $wf)
                            @php
                                $assignedCount = (int) ($wf->assigned_leads_count ?? 0);
                                $enrichedCount = (int) ($wf->enriched_leads_count ?? $wf->enriched_leads ?? 0);
                                $unassigned = max(0, (int) ($wf->ready_to_assign_count ?? 0));
                                $canAssign = $unassigned > 0 && ! in_array($wf->status, ['mapping', 'failed'], true);
                                $totalLeads = (int) ($wf->total_leads ?? 0);
                                $uploadOnly = $wf->isImportOnly();
                                $isActive = in_array($wf->status, ['pending', 'extracting', 'paused'], true);
                                $assignedAgents = collect($wf->assigned_agents ?? []);
                                $dispositionTotal = (int) ($wf->disposition_total ?? 0);
                                $dispositionBreakdown = collect($wf->disposition_breakdown ?? []);
                                if ($uploadOnly) {
                                    $progressDone = $wf->ingestion_complete || $wf->status === 'completed';
                                    $progressPct = $progressDone && $totalLeads > 0 ? 100 : 0;
                                    $progressText = $isActive && $wf->status !== 'paused'
                                        ? number_format($totalLeads).' uploaded…'
                                        : number_format($totalLeads).' / '.number_format($totalLeads).' uploaded';
                                } else {
                                    $progressPct = $totalLeads > 0 ? min(100, (int) round(($enrichedCount / $totalLeads) * 100)) : 0;
                                    $progressText = number_format($enrichedCount).' / '.number_format($totalLeads).' enriched';
                                }
                            @endphp
                            <tr data-workflow-id="{{ $wf->id }}" data-workflow-status="{{ $wf->status }}" data-processing-mode="{{ $wf->processing_mode }}" data-agent-restricted="{{ $wf->agent_restricted ? '1' : '0' }}">
                                <td class="col-import-name" data-label="Import / Campaign">
                                    <div class="import-workflow-name-row">
                                        <div class="import-workflow-name" data-import-name>{{ $wf->name }}</div>
                                        <button type="button"
                                            class="import-action-btn import-edit-btn"
                                            title="Edit name / file"
                                            aria-label="Edit import"
                                            data-import-edit-open
                                            data-workflow-id="{{ $wf->id }}"
                                            data-workflow-name="{{ $wf->name }}"
                                            data-workflow-filename="{{ $wf->original_filename }}"
                                            data-edit-url="{{ route('admin.workflows.update', $wf->id) }}">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                    </div>
                                    @if ($wf->campaign)
                                        <div class="mt-1">
                                            @include('partials.campaign-chip', ['campaign' => $wf->campaign, 'compact' => true])
                                        </div>
                                    @endif
                                </td>
                                <td class="col-file" data-label="File">
                                    <div class="import-workflow-name-row">
                                        <span class="import-workflow-file" title="{{ $wf->original_filename }}" data-import-filename>{{ $wf->original_filename ?: '—' }}</span>
                                        <button type="button"
                                            class="import-action-btn import-edit-btn"
                                            title="Edit file name"
                                            aria-label="Edit file name"
                                            data-import-edit-open
                                            data-workflow-id="{{ $wf->id }}"
                                            data-workflow-name="{{ $wf->name }}"
                                            data-workflow-filename="{{ $wf->original_filename }}"
                                            data-edit-url="{{ route('admin.workflows.update', $wf->id) }}">
                                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                    </div>
                                </td>
                                <td data-label="Status"><x-workflow-status-pill :status="$wf->status" :processing-mode="$wf->processing_mode" /></td>
                                <td class="col-progress min-w-[148px]" data-label="Progress">
                                    @if ($totalLeads === 0 && $wf->status === 'mapping')
                                        <span class="text-xs text-zinc-400">Awaiting setup</span>
                                    @else
                                        <div class="space-y-1">
                                            <div class="app-progress-track h-1.5">
                                                <div class="app-progress-fill bg-emerald-500" style="width: {{ $progressPct }}%"></div>
                                            </div>
                                            <p class="import-workflow-progress-text">{{ $progressText }}</p>
                                        </div>
                                    @endif
                                </td>
                                <td class="import-workflow-stat" data-label="Total">{{ number_format($totalLeads) }}</td>
                                <td class="import-workflow-stat" data-label="Enriched" data-import-enriched>{{ number_format($enrichedCount) }}</td>
                                <td class="import-workflow-stat import-workflow-stat-success col-assigned" data-label="Assigned / Agents" data-import-assigned>
                    <div class="import-assigned-cell">
                        <strong class="import-assigned-total">{{ number_format($assignedCount) }}</strong>
                        @if ($assignedAgents->isNotEmpty())
                            <ul class="import-assigned-agents" title="Agents this file is assigned to">
                                @foreach ($assignedAgents->take(4) as $agent)
                                    <li>
                                        <span class="import-assigned-agents__name">{{ $agent['name'] }}</span>
                                        <span class="import-assigned-agents__count">{{ number_format((int) $agent['count']) }}</span>
                                    </li>
                                @endforeach
                                @if ($assignedAgents->count() > 4)
                                    <li>
                                        <button
                                            type="button"
                                            class="import-assigned-agents__more"
                                            data-assigned-agents-more
                                            data-workflow-name="{{ $wf->name }}"
                                            data-assigned-total="{{ (int) $assignedCount }}"
                                            data-assigned-agents='@json($assignedAgents->values())'
                                        >+{{ $assignedAgents->count() - 4 }} more</button>
                                    </li>
                                @endif
                            </ul>
                        @elseif ($assignedCount === 0)
                            <span class="import-assigned-empty">No agents yet</span>
                        @endif
                    </div>
                </td>
                                <td class="col-dispositions" data-label="Dispositions" data-import-dispositions>
                                    @if ($dispositionTotal > 0)
                                        <button
                                            type="button"
                                            class="import-disposition-btn"
                                            data-import-dispositions-open
                                            data-workflow-id="{{ $wf->id }}"
                                            data-workflow-name="{{ $wf->name }}"
                                            data-dispositions-url="{{ route('admin.workflows.dispositions', $wf->id) }}"
                                            title="View all dispositions submitted by agents"
                                        >
                                            <strong class="import-disposition-btn__total">{{ number_format($dispositionTotal) }}</strong>
                                            <span class="import-disposition-btn__label">total</span>
                                            @if ($dispositionBreakdown->isNotEmpty())
                                                <span class="import-disposition-btn__top">{{ $dispositionBreakdown->first()['label'] ?? '' }}</span>
                                            @endif
                                        </button>
                                    @else
                                        <span class="import-disposition-empty">0</span>
                                    @endif
                                </td>
                                <td class="import-workflow-stat import-workflow-stat-warning" data-label="Ready">
                                    @if ($unassigned > 0)
                                        <a href="{{ route('admin.workflows.show', ['workflow' => $wf->id, 'pool' => 'unassigned']) }}"
                                            class="import-workflow-unassigned-link"
                                            title="View and review unassigned leads">{{ number_format($unassigned) }}</a>
                                    @else
                                        {{ number_format($unassigned) }}
                                    @endif
                                </td>
                                <td class="col-assign" data-label="Assign">
                                    <div class="import-assign-actions">
                                        @if ($canAssign)
                                            <button type="button" class="app-btn app-btn-success app-btn-sm import-assign-btn"
                                                data-import-assign-open data-workflow-id="{{ $wf->id }}"
                                                data-workflow-name="{{ $wf->name }}" data-workflow-total="{{ $totalLeads }}"
                                                data-workflow-enriched="{{ $enrichedCount }}"
                                                data-workflow-assigned="{{ $assignedCount }}"
                                                data-workflow-remaining="{{ $unassigned }}">Assign</button>
                                        @endif
                                        @if ($assignedCount > 0)
                                            <button type="button" class="app-btn app-btn-secondary app-btn-sm import-unassign-btn"
                                                data-import-unassign-open
                                                data-workflow-id="{{ $wf->id }}"
                                                data-workflow-name="{{ $wf->name }}"
                                                data-workflow-total="{{ $totalLeads }}"
                                                data-workflow-assigned="{{ $assignedCount }}"
                                                data-workflow-remaining="{{ $unassigned }}"
                                                data-assigned-agents='@json($assignedAgents->values())'
                                                title="Return assigned leads to the pool">Unassign</button>
                                        @endif
                                        @if (! $canAssign && $assignedCount === 0)
                                            <span class="import-assign-empty">&mdash;</span>
                                        @endif
                                    </div>
                                </td>
                                <td data-label="Agents">
                                    <div class="import-agent-visibility">
                                        <button type="button"
                                            class="app-btn app-btn-sm {{ $wf->agent_restricted ? 'app-btn-danger' : 'app-btn-secondary' }} import-restrict-btn"
                                            data-import-restrict-toggle
                                            data-workflow-id="{{ $wf->id }}"
                                            data-restricted="{{ $wf->agent_restricted ? '1' : '0' }}"
                                            data-toggle-url="{{ route('admin.workflows.update', $wf->id) }}"
                                            title="{{ $wf->agent_restricted ? 'Allow agents to see this file' : 'Hide this file from agent dialer' }}">
                                            {{ $wf->agent_restricted ? 'Restricted' : 'Visible' }}
                                        </button>
                                        <button type="button"
                                            class="app-btn app-btn-sm app-btn-secondary import-share-btn"
                                            data-import-share-open
                                            data-workflow-id="{{ $wf->id }}"
                                            data-workflow-name="{{ $wf->name }}"
                                            data-access-url="{{ route('admin.workflows.agent-access', $wf->id) }}"
                                            data-access-sync-url="{{ route('admin.workflows.agent-access.sync', $wf->id) }}"
                                            title="Choose which agents can see this file">
                                            Share
                                        </button>
                                    </div>
                                </td>
                                <td class="text-right col-actions" data-label="Actions">
                                    @include('workflows.partials.import-row-actions', ['wf' => $wf, 'totalLeads' => $totalLeads])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-data-table>
            </div>
        @endif
    </div>
    @endunless

    @if ($assignedLeadsOnly && isset($leads) && $leads instanceof \Illuminate\Contracts\Pagination\Paginator)
        @php
            $selectedWorkflowIds = collect(request()->input('workflow_ids', []))
                ->map(fn ($id) => (string) $id)
                ->filter()
                ->values();
            if ($selectedWorkflowIds->isEmpty() && filled(request('workflow_id'))) {
                $selectedWorkflowIds = collect([(string) request('workflow_id')]);
            }
        @endphp
        <div class="admin-dash-card {{ $assignedLeadsOnly ? 'assigned-leads-panel' : 'mt-6' }}">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4 assigned-leads-panel__toolbar">
                @unless ($assignedLeadsOnly && ($isAssignedLeadsPage || request()->routeIs('admin.workflows.*')))
                <div>
                    <h3 class="admin-dash-section-title">Assigned leads</h3>
                </div>
                @endunless
                <form method="GET" action="{{ $importsIndexRoute }}" class="active-leads-filters flex flex-wrap items-end gap-2.5 {{ $assignedLeadsOnly && ($isAssignedLeadsPage || request()->routeIs('admin.workflows.*')) ? 'assigned-leads-filters--page' : '' }}" data-active-leads-form>
                    @unless (request()->routeIs('admin.workflows.*') || $isAssignedLeadsPage)
                        <input type="hidden" name="section" value="imports">
                    @endunless
                    <div class="active-leads-file-hidden" data-active-leads-file-hidden aria-hidden="true">
                        @foreach ($selectedWorkflowIds as $selectedId)
                            <input type="hidden" name="workflow_ids[]" value="{{ $selectedId }}" data-active-leads-file-hidden-input>
                        @endforeach
                    </div>
                    <div class="active-leads-filters__field active-leads-filters__field--search">
                        <label class="active-leads-filters__label" for="active-leads-search">Search</label>
                        <input id="active-leads-search" type="search" name="search" value="{{ request('search') }}" placeholder="Search leads…" class="app-input app-input-sm">
                    </div>
                    <div class="active-leads-filters__field">
                        <label class="active-leads-filters__label" for="active-leads-phase">Phase</label>
                        <select id="active-leads-phase" name="phase" class="app-input app-input-sm js-pretty-select" data-pretty-select onchange="this.form.submit()">
                            <option value="">All phases</option>
                            @foreach ($pipelinePhases ?? [] as $value => $label)
                                <option value="{{ $value }}" @selected(request('phase') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Filter</button>
                    @if (request()->anyFilled(['search', 'phase', 'workflow_id', 'workflow_ids']))
                        <a href="{{ $importsIndexRoute }}" class="app-btn app-btn-ghost app-btn-sm">Clear</a>
                    @endif
                </form>
            </div>

            <div id="uploaded-files-modal" class="uploaded-files-modal" hidden aria-hidden="true" role="dialog"
                aria-labelledby="uploaded-files-modal-title" aria-modal="true">
                <div class="uploaded-files-modal__backdrop" data-uploaded-files-close></div>
                <div class="uploaded-files-modal__panel" role="document">
                    <div class="uploaded-files-modal__header">
                        <div>
                            <h3 id="uploaded-files-modal-title" class="uploaded-files-modal__title">Uploaded files</h3>
                            <p class="uploaded-files-modal__desc">Select one or more import files to filter assigned leads.</p>
                        </div>
                        <button type="button" class="app-modal-close" data-uploaded-files-close aria-label="Close">&times;</button>
                    </div>
                    <div class="uploaded-files-modal__toolbar">
                        <input id="uploaded-files-search" type="search" class="app-input app-input-sm"
                            placeholder="Search files…" data-uploaded-files-search
                            aria-label="Search uploaded files" autocomplete="off">
                    </div>
                    <div class="uploaded-files-modal__list active-leads-file-checks" role="group" aria-label="Uploaded files" data-uploaded-files-list>
                        <label class="active-leads-file-check" data-uploaded-file-row data-file-label="all uploaded files">
                            <span>All uploaded files</span>
                            <input
                                type="checkbox"
                                class="active-leads-file-check__input"
                                data-active-leads-file-all
                                @checked($selectedWorkflowIds->isEmpty())
                            >
                        </label>
                        @foreach (($uploadedWorkflows ?? collect()) as $file)
                            @php
                                $fileLabel = trim((string) ($file->original_filename ?: $file->name ?: 'Import #'.$file->id));
                                if (($file->total_leads ?? 0) > 0) {
                                    $fileLabel .= ' ('.number_format((int) $file->total_leads).')';
                                }
                                $isChecked = $selectedWorkflowIds->contains((string) $file->id);
                            @endphp
                            <label class="active-leads-file-check" data-uploaded-file-row data-file-label="{{ strtolower($fileLabel) }}">
                                <span title="{{ $fileLabel }}">{{ $fileLabel }}</span>
                                <input
                                    type="checkbox"
                                    value="{{ $file->id }}"
                                    class="active-leads-file-check__input"
                                    data-active-leads-file-id
                                    @checked($isChecked)
                                >
                            </label>
                        @endforeach
                    </div>
                    <div class="uploaded-files-modal__actions">
                        <button type="button" class="app-btn app-btn-ghost app-btn-sm" data-uploaded-files-close>Cancel</button>
                        <button type="button" class="app-btn app-btn-success app-btn-sm" data-uploaded-files-apply>Apply</button>
                    </div>
                </div>
            </div>
            <x-data-table :paginator="$leads" min-width="960px" class="assigned-leads-data-table">
                <table class="assigned-leads-table">
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
                        @forelse ($leads as $lead)
                            @php
                                $display = \App\Support\LeadContactDisplay::for($lead);
                                $stateName = \App\Support\UsAreaCodeState::resolve(
                                    $lead->state,
                                    $lead->normalized_phone ?: ($lead->input_phone ?: ($display['phone'] ?? null))
                                );
                            @endphp
                            <tr data-lead-id="{{ $lead->id }}">
                                <td>
                                    <div class="font-bold text-zinc-900 lead-business-name">{{ $lead->business_name }}</div>
                                    <div class="text-xs text-zinc-400">{{ trim(implode(', ', array_filter([$lead->city, $stateName]))) ?: '—' }}</div>
                                </td>
                                <td>
                                    @if ($lead->campaign)
                                        @include('partials.campaign-chip', ['campaign' => $lead->campaign, 'compact' => true])
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-sm text-zinc-600 lead-contact-cell">{{ \App\Support\LeadContactDisplay::cell($display['phone']) ?: '—' }}</td>
                                <td><span class="app-badge app-badge-muted">{{ \App\Support\SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</span></td>
                                <td class="text-right">
                                    <a href="{{ \App\Support\LeadRoute::show($lead, true) }}" class="app-btn app-btn-secondary app-btn-sm">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="admin-dash-empty">No assigned leads found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </div>
    @endif

    @include('workflows.partials.import-modals', [
        'setterTeamLeads' => $setterTeamLeads ?? $team ?? collect(),
        'activeSetters' => $activeSetters ?? collect(),
        'setterTeamMemberMap' => $setterTeamMemberMap ?? [],
        'campaignNames' => $campaignNames ?? collect(),
        'activeSetterCount' => $activeSetterCount ?? 0,
    ])
</div>
@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-active-leads-form]');
    const modal = document.getElementById('uploaded-files-modal');
    const hiddenWrap = form?.querySelector('[data-active-leads-file-hidden]');
    const openBtns = document.querySelectorAll('[data-uploaded-files-open]');
    const countEl = document.querySelector('[data-uploaded-files-count]');
    const searchInput = modal?.querySelector('[data-uploaded-files-search]');
    const allBox = modal?.querySelector('[data-active-leads-file-all]');
    const fileBoxes = () => [...(modal?.querySelectorAll('[data-active-leads-file-id]') || [])];

    const syncCountBadge = () => {
        if (!countEl) return;
        const selected = fileBoxes().filter((box) => box.checked).length;
        const show = selected > 0;
        countEl.hidden = !show;
        countEl.textContent = show ? String(selected) : '';
    };

    const syncHiddenInputs = () => {
        if (!hiddenWrap) return;
        hiddenWrap.innerHTML = '';
        fileBoxes().filter((box) => box.checked).forEach((box) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'workflow_ids[]';
            input.value = box.value;
            input.setAttribute('data-active-leads-file-hidden-input', '');
            hiddenWrap.appendChild(input);
        });
        syncCountBadge();
    };

    const openModal = () => {
        if (!modal) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('uploaded-files-modal-open');
        searchInput?.focus();
    };

    const closeModal = () => {
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('uploaded-files-modal-open');
        if (searchInput) {
            searchInput.value = '';
            modal.querySelectorAll('[data-uploaded-file-row]').forEach((row) => {
                row.hidden = false;
            });
        }
    };

    openBtns.forEach((btn) => btn.addEventListener('click', openModal));
    modal?.querySelectorAll('[data-uploaded-files-close]').forEach((btn) => {
        btn.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    allBox?.addEventListener('change', () => {
        if (allBox.checked) {
            fileBoxes().forEach((box) => { box.checked = false; });
        }
    });

    modal?.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement) || !target.matches('[data-active-leads-file-id]')) {
            return;
        }
        if (target.checked && allBox) {
            allBox.checked = false;
        }
        if (fileBoxes().every((box) => !box.checked) && allBox) {
            allBox.checked = true;
        }
    });

    searchInput?.addEventListener('input', () => {
        const q = searchInput.value.trim().toLowerCase();
        modal.querySelectorAll('[data-uploaded-file-row]').forEach((row) => {
            const label = (row.getAttribute('data-file-label') || '').toLowerCase();
            row.hidden = q !== '' && !label.includes(q);
        });
    });

    modal?.querySelector('[data-uploaded-files-apply]')?.addEventListener('click', () => {
        syncHiddenInputs();
        closeModal();
        form?.requestSubmit?.() || form?.submit();
    });

    syncCountBadge();

    if (!window.__importRestrictClickBound) {
        window.__importRestrictClickBound = true;
        document.addEventListener('click', async (event) => {
            const btn = event.target.closest('[data-import-restrict-toggle]');
            if (!btn) return;
            event.preventDefault();
            if (btn.dataset.busy === '1') return;
            const url = btn.dataset.toggleUrl;
            if (!url) return;
            const currentlyRestricted = btn.dataset.restricted === '1';
            const token = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || '';
            btn.dataset.busy = '1';
            btn.disabled = true;
            try {
                const res = await fetch(url, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ agent_restricted: !currentlyRestricted }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.ok === false) {
                    throw new Error(data.message || 'Could not update file restriction.');
                }
                const restricted = Boolean(data.agent_restricted);
                btn.dataset.restricted = restricted ? '1' : '0';
                btn.textContent = restricted ? 'Restricted' : 'Visible';
                btn.classList.toggle('app-btn-danger', restricted);
                btn.classList.toggle('app-btn-secondary', !restricted);
                btn.title = restricted ? 'Allow agents to see this file' : 'Hide this file from agent dialer';
                const row = btn.closest('tr[data-workflow-id]');
                if (row) {
                    row.dataset.agentRestricted = restricted ? '1' : '0';
                }
                window.showToast?.(data.message || (restricted ? 'Restricted from agents.' : 'Visible to agents.'), 'success');
                document.dispatchEvent(new CustomEvent('workspace:sync-request'));
            } catch (err) {
                window.showToast?.(err.message || 'Could not update file restriction.', 'error');
            } finally {
                btn.dataset.busy = '0';
                btn.disabled = false;
            }
        });
    }
})();
</script>
<script>
(() => {
    function ensureAssignedAgentsModal() {
        let modal = document.getElementById('import-assigned-agents-modal');
        if (modal && !modal.querySelector('[data-assigned-agents-meta]')) {
            modal.remove();
            modal = null;
        }
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.id = 'import-assigned-agents-modal';
        modal.className = 'import-assigned-agents-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="import-assigned-agents-modal__backdrop" data-assigned-agents-close></div>
            <div class="import-assigned-agents-modal__panel" role="dialog" aria-modal="true" aria-labelledby="import-assigned-agents-title">
                <header class="import-assigned-agents-modal__header">
                    <div>
                        <h3 id="import-assigned-agents-title" class="import-assigned-agents-modal__title">Assigned agents</h3>
                        <p class="import-assigned-agents-modal__subtitle" data-assigned-agents-subtitle></p>
                        <div class="import-assigned-agents-modal__meta" data-assigned-agents-meta></div>
                    </div>
                    <button type="button" class="import-assigned-agents-modal__close" data-assigned-agents-close aria-label="Close">&times;</button>
                </header>
                <div class="import-assigned-agents-modal__body" data-assigned-agents-body></div>
            </div>
        `;
        document.body.appendChild(modal);
        return modal;
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function parseAgents(raw) {
        if (!raw) return [];
        if (Array.isArray(raw)) return raw;
        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }

    function openAssignedAgentsModal(btn) {
        const modal = ensureAssignedAgentsModal();
        const agents = parseAgents(btn.getAttribute('data-assigned-agents'));
        const workflowName = btn.getAttribute('data-workflow-name') || 'Import';
        const total = Number(btn.getAttribute('data-assigned-total') || 0);
        const subtitle = modal.querySelector('[data-assigned-agents-subtitle]');
        const meta = modal.querySelector('[data-assigned-agents-meta]');
        const body = modal.querySelector('[data-assigned-agents-body]');

        if (subtitle) {
            subtitle.textContent = workflowName;
        }

        if (meta) {
            const agentLabel = `${agents.length} agent${agents.length === 1 ? '' : 's'}`;
            meta.innerHTML = `
                <span class="import-assigned-agents-modal__chip">${total.toLocaleString()} assigned</span>
                <span class="import-assigned-agents-modal__chip">${escapeHtml(agentLabel)}</span>
            `;
        }

        if (body) {
            if (!agents.length) {
                body.innerHTML = '<p class="import-assigned-agents-modal__empty">No agents assigned.</p>';
            } else {
                const rows = agents.map((agent) => {
                    const name = escapeHtml(agent?.name || `Agent #${agent?.user_id || ''}`);
                    const count = Number(agent?.count ?? 0).toLocaleString();
                    return `<tr><td>${name}</td><td>${count}</td></tr>`;
                }).join('');
                body.innerHTML = `
                    <table class="import-assigned-agents-modal__table">
                        <thead>
                            <tr>
                                <th scope="col">Agent</th>
                                <th scope="col">Leads</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                `;
            }
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    function closeAssignedAgentsModal() {
        const modal = document.getElementById('import-assigned-agents-modal');
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
    }

    function bindAssignedAgentsMore() {
        if (document.documentElement.dataset.assignedAgentsMoreBound === '1') {
            return;
        }
        document.documentElement.dataset.assignedAgentsMoreBound = '1';

        document.addEventListener('click', (event) => {
            const moreBtn = event.target.closest('[data-assigned-agents-more]');
            if (moreBtn) {
                event.preventDefault();
                event.stopPropagation();
                openAssignedAgentsModal(moreBtn);
                return;
            }

            const closer = event.target.closest('[data-assigned-agents-close]');
            if (closer || event.target.id === 'import-assigned-agents-modal') {
                closeAssignedAgentsModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAssignedAgentsModal();
            }
        });
    }

    bindAssignedAgentsMore();
    document.addEventListener('turbo:load', bindAssignedAgentsMore);
    document.addEventListener('turbo:render', bindAssignedAgentsMore);
})();
</script>
<script>
(() => {
    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function ensureModal(id, html) {
        let modal = document.getElementById(id);
        if (modal) return modal;
        modal = document.createElement('div');
        modal.id = id;
        modal.className = 'import-utility-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = html;
        document.body.appendChild(modal);
        return modal;
    }

    function openModal(modal) {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.import-utility-modal.is-open, .import-assigned-agents-modal.is-open')) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    async function openDispositionsModal(btn) {
        const modal = ensureModal('import-dispositions-modal', `
            <div class="import-utility-modal__backdrop" data-utility-close></div>
            <div class="import-utility-modal__panel" role="dialog" aria-modal="true">
                <header class="import-utility-modal__header">
                    <div>
                        <h3 class="import-utility-modal__title">Dispositions</h3>
                        <p class="import-utility-modal__subtitle" data-disp-subtitle></p>
                    </div>
                    <button type="button" class="import-utility-modal__close" data-utility-close aria-label="Close">&times;</button>
                </header>
                <div class="import-utility-modal__body" data-disp-body>Loading…</div>
            </div>
        `);
        const subtitle = modal.querySelector('[data-disp-subtitle]');
        const body = modal.querySelector('[data-disp-body]');
        subtitle.textContent = btn.dataset.workflowName || 'Import';
        body.innerHTML = '<p class="import-utility-modal__empty">Loading dispositions…</p>';
        openModal(modal);

        try {
            const res = await fetch(btn.dataset.dispositionsUrl, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Could not load dispositions.');

            const total = Number(data.total || 0);
            const breakdown = Array.isArray(data.breakdown) ? data.breakdown : [];
            subtitle.textContent = `${data.workflow_name || btn.dataset.workflowName || 'Import'} · ${total.toLocaleString()} total`;

            const cards = breakdown.map((item) => `
                <article class="import-disposition-card">
                    <span class="import-disposition-card__name">${escapeHtml(item.label)}</span>
                    <strong class="import-disposition-card__count">${Number(item.count || 0).toLocaleString()}</strong>
                    <span class="import-disposition-card__meta">submitted</span>
                </article>
            `).join('');

            body.innerHTML = `
                <div class="import-disposition-summary import-disposition-summary--cards-only">
                    <div class="import-disposition-summary__total">
                        <strong>${total.toLocaleString()}</strong>
                        <span>total submitted</span>
                    </div>
                    <div class="import-disposition-cards">
                        ${cards || '<p class="import-utility-modal__empty">No dispositions submitted yet.</p>'}
                    </div>
                </div>
            `;
        } catch (err) {
            body.innerHTML = `<p class="import-utility-modal__empty">${escapeHtml(err.message || 'Could not load dispositions.')}</p>`;
        }
    }

    async function openShareModal(btn) {
        const modal = ensureModal('import-share-modal', `
            <div class="import-utility-modal__backdrop" data-utility-close></div>
            <div class="import-utility-modal__panel" role="dialog" aria-modal="true">
                <header class="import-utility-modal__header">
                    <div>
                        <h3 class="import-utility-modal__title">Share file with agents</h3>
                        <p class="import-utility-modal__subtitle" data-share-subtitle></p>
                    </div>
                    <button type="button" class="import-utility-modal__close" data-utility-close aria-label="Close">&times;</button>
                </header>
                <div class="import-utility-modal__body" data-share-body>Loading…</div>
                <footer class="import-utility-modal__footer">
                    <button type="button" class="app-btn app-btn-secondary app-btn-sm" data-utility-close>Cancel</button>
                    <button type="button" class="app-btn app-btn-success app-btn-sm" data-share-save>Save access</button>
                </footer>
            </div>
        `);
        const subtitle = modal.querySelector('[data-share-subtitle]');
        const body = modal.querySelector('[data-share-body]');
        const saveBtn = modal.querySelector('[data-share-save]');
        subtitle.textContent = btn.dataset.workflowName || 'Import';
        body.innerHTML = '<p class="import-utility-modal__empty">Loading agents…</p>';
        modal.dataset.syncUrl = btn.dataset.accessSyncUrl || '';
        openModal(modal);

        try {
            const res = await fetch(btn.dataset.accessUrl, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Could not load agent access.');
            const agents = Array.isArray(data.agents) ? data.agents : [];
            body.innerHTML = `
                <p class="import-share-hint">Leave all unchecked to allow every agent who has assigned leads (when the file is Visible). Check agents to limit visibility to only them.</p>
                <div class="import-share-list">
                    ${agents.map((agent) => `
                        <label class="import-share-row">
                            <input type="checkbox" value="${Number(agent.id)}" ${agent.selected ? 'checked' : ''}>
                            <span>${escapeHtml(agent.name)}</span>
                        </label>
                    `).join('') || '<p class="import-utility-modal__empty">No active agents found.</p>'}
                </div>
            `;
        } catch (err) {
            body.innerHTML = `<p class="import-utility-modal__empty">${escapeHtml(err.message || 'Could not load agents.')}</p>`;
        }

        saveBtn.onclick = async () => {
            const ids = [...body.querySelectorAll('input[type="checkbox"]:checked')].map((el) => Number(el.value)).filter(Boolean);
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';
            try {
                const res = await fetch(modal.dataset.syncUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ user_ids: ids }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Could not save access.');
                window.showToast?.(data.message || 'File access updated.', 'success');
                closeModal(modal);
            } catch (err) {
                window.showToast?.(err.message || 'Could not save access.', 'error');
            } finally {
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save access';
            }
        };
    }

    document.addEventListener('click', (event) => {
        const dispBtn = event.target.closest('[data-import-dispositions-open]');
        if (dispBtn) {
            event.preventDefault();
            openDispositionsModal(dispBtn);
            return;
        }
        const shareBtn = event.target.closest('[data-import-share-open]');
        if (shareBtn) {
            event.preventDefault();
            openShareModal(shareBtn);
            return;
        }
        const closer = event.target.closest('[data-utility-close]');
        if (closer) {
            closeModal(closer.closest('.import-utility-modal'));
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.import-utility-modal.is-open').forEach((modal) => closeModal(modal));
        }
    });
})();
</script>
@endpush
@endif
