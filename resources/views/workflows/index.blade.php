@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', request()->is('admin*') ? 'Leads' : 'My Lead Pool')

@section('content')
<div class="app-page space-y-6">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="app-page-title">{{ request()->is('admin*') ? 'Leads' : 'My Lead Pool' }}</h1>
            <p class="app-page-subtitle">
                @if(request()->is('admin*'))
                    Import lists, review enriched leads, and release to your team.
                @else
                    Work assigned leads and log activity toward daily goals.
                @endif
            </p>
        </div>
        @if(request()->is('admin*'))
            <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-primary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Import leads
            </a>
        @endif
    </div>

    @if(isset($dailyMetrics))
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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

    <!-- Main Grid -->
    <div class="grid grid-cols-1 {{ request()->is('admin*') ? 'lg:grid-cols-3' : '' }} gap-6">
        
        <!-- Left 2 Columns: Active CRM Leads -->
        <div class="{{ request()->is('admin*') ? 'lg:col-span-2' : '' }} space-y-6">
            <div class="app-card app-card-padded">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h2 class="app-section-title">{{ request()->is('admin*') ? 'Active leads' : 'Assigned leads' }}</h2>
                        <p class="app-section-desc">{{ request()->is('admin*') ? 'Leads released to your workspace.' : 'Update stage and log outreach.' }}</p>
                    </div>
                    
                    <!-- Search & Filter Form -->
                    <form method="GET" action="{{ request()->is('admin*') ? route('admin.workflows.index') : route('portal.dashboard') }}" class="flex flex-wrap items-center gap-2">
                        <div class="app-search-wrap">
                            <svg class="app-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search business, owner…" class="app-input">
                        </div>
                        <select name="stage" onchange="this.form.submit()" class="app-input !w-auto">
                            <option value="">All Stages</option>
                            @foreach($crmStages ?? [] as $value => $label)
                                <option value="{{ $value }}" {{ request('stage') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="tier" onchange="this.form.submit()" class="app-input !w-auto">
                            <option value="">All Tiers</option>
                            @foreach($leadTiers ?? [] as $value => $tier)
                                <option value="{{ $value }}" {{ request('tier') === $value ? 'selected' : '' }}>{{ $tier['label'] }}</option>
                            @endforeach
                        </select>
                        @if(request()->anyFilled(['search', 'stage', 'tier']))
                            <a href="{{ request()->is('admin*') ? route('admin.workflows.index') : route('portal.dashboard') }}" class="app-btn app-btn-secondary app-btn-sm" title="Clear filters">Clear</a>
                        @endif
                    </form>
                </div>

                <!-- Leads Table -->
                @if($leads->isEmpty())
                    <div class="app-empty-state">
                        <div class="app-empty-state-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <p class="app-empty-state-title">No leads found</p>
                        <p class="app-empty-state-desc">Import a list or adjust your filters.</p>
                    </div>
                @else
                    <x-data-table :paginator="$leads">
                        <table>
                            <thead>
                                <tr>
                                    <th>Business</th>
                                    <th>Owner</th>
                                    <th>Contact</th>
                                    <th>Processor</th>
                                    <th>Stage</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="workspace-sync-leads-body">
                                @foreach($leads as $lead)
                                    <tr>
                                        <td>
                                            <div class="font-bold text-zinc-900">{{ $lead->business_name }}</div>
                                            @if($lead->address)
                                                <div class="text-xs text-zinc-500 mt-0.5">{{ $lead->address }}</div>
                                            @endif
                                            <div class="text-[10px] text-zinc-400 font-normal mt-0.5">
                                                {{ $lead->city }}, {{ $lead->state }}
                                            </div>
                                        </td>
                                        <td class="font-medium text-zinc-600">
                                            {{ $lead->owner_name ?: 'Not Found' }}
                                        </td>
                                        <td>
                                            @if($lead->direct_email && $lead->direct_email !== 'Not Publicly Available')
                                                <div class="text-zinc-700">{{ $lead->direct_email }}</div>
                                            @endif
                                            @if($lead->direct_phone && $lead->direct_phone !== 'Not Publicly Available')
                                                <div class="text-xs text-zinc-400 mt-0.5">{{ $lead->direct_phone }}</div>
                                            @endif
                                            @if(!$lead->direct_email && !$lead->direct_phone)
                                                <span class="text-xs text-zinc-400 italic">None available</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="app-badge app-badge-info">{{ $lead->payment_processor ?: 'Unknown' }}</span>
                                        </td>
                                        <td>
                                            <div class="text-[10px] font-semibold text-zinc-400 mb-1">{{ \App\Support\SalesOps::tierLabel($lead->tier) }}</div>
                                            <span class="app-badge app-badge-muted">{{ \App\Support\SalesOps::crmStageLabel($lead->stage) }}</span>
                                        </td>
                                        <td class="text-right">
                                            <a href="{{ route('portal.leads.show', $lead->id) }}" class="app-icon-btn" title="Open lead">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-data-table>
                @endif
            </div>
        </div>

        @if(request()->is('admin*'))
        <div class="space-y-6">
            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-4">Recent imports</h2>
                
                @if($workflows->isEmpty())
                    <p class="text-sm text-zinc-500">No imports yet.</p>
                    <a href="{{ route('admin.workflows.create') }}" class="app-link text-sm inline-block mt-3">Import your first list</a>
                @else
                    <div id="workspace-sync-workflows" class="space-y-3">
                        @foreach($workflows as $wf)
                            <div class="app-import-card">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="app-import-card-title">{{ $wf->name }}</h3>
                                        <p class="app-import-card-meta">{{ $wf->original_filename }}</p>
                                    </div>
                                    <x-workflow-status-pill :status="$wf->status" />
                                </div>
                                <div class="mt-3 flex items-center justify-between text-xs text-zinc-500">
                                    <span>{{ $wf->processed_leads }} / {{ $wf->total_leads }} processed</span>
                                    <span>{{ $wf->created_at->diffForHumans() }}</span>
                                </div>
                                <div class="mt-3 flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.workflows.show', $wf->id) }}" class="app-link text-xs">
                                        {{ $wf->status === 'mapping' ? 'Continue setup' : 'Open' }}
                                    </a>
                                    @if(in_array($wf->status, ['pending', 'extracting']))
                                        <form method="POST" action="{{ route('admin.workflows.pause', $wf->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="app-link text-xs text-amber-700">Pause</button>
                                        </form>
                                    @endif
                                    @if($wf->status === 'paused')
                                        <form method="POST" action="{{ route('admin.workflows.resume', $wf->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="app-link text-xs text-emerald-700">Resume</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.workflows.destroy', $wf->id) }}" class="inline" onsubmit="return confirm('Delete this pipeline and all {{ $wf->total_leads }} lead records from the database?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="app-link text-xs text-rose-600">Delete</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <x-pagination :paginator="$workflows" class="app-data-table-footer !border-t !border-slate-100 !bg-transparent !px-0" />
                @endif
            </div>
        </div>
        @endif

    </div>
</div>
@endsection
