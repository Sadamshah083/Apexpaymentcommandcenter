@extends('layouts.admin')

@section('title', $campaign->name)

@section('content')
    @php use App\Support\LeadRoute; @endphp

    <div class="app-page campaigns-show-page space-y-5">
        <div class="app-page-header flex flex-row items-start justify-between gap-3">
            <div class="min-w-0">
                <x-back-link :href="route('admin.campaigns.index')" label="All campaigns" />
                <h1 class="app-page-title mt-2">{{ $campaign->name }}</h1>
            </div>
        </div>

        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            @foreach ([
                'total' => 'Total',
                'imported' => 'Imported',
                'enriched' => 'Enriched',
                'ready_to_distribute' => 'Ready to assign',
                'failed' => 'Failed',
            ] as $key => $label)
                <div class="app-card app-card-padded text-center">
                    <p class="text-xs text-zinc-400 font-semibold uppercase tracking-wide">{{ $label }}</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ number_format($counts[$key] ?? 0) }}</p>
                </div>
            @endforeach
        </div>

        @php $callKpis = $callKpis ?? ['dials' => 0, 'connected' => 0, 'connect_rate' => 0, 'dispositions' => []]; @endphp
        <div class="app-card app-card-padded space-y-4">
            <h3 class="app-section-title">Call KPIs</h3>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="text-center">
                    <p class="text-xs text-zinc-400 font-semibold uppercase tracking-wide">Calls</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ number_format($callKpis['dials'] ?? 0) }}</p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-zinc-400 font-semibold uppercase tracking-wide">Connected</p>
                    <p class="text-2xl font-bold text-emerald-700 mt-1">{{ number_format($callKpis['connected'] ?? 0) }}</p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-zinc-400 font-semibold uppercase tracking-wide">Connect rate</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ number_format($callKpis['connect_rate'] ?? 0, 1) }}%</p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-zinc-400 font-semibold uppercase tracking-wide">Dispositioned</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ number_format($callKpis['dispositioned'] ?? 0) }}</p>
                </div>
            </div>
            @if (! empty($callKpis['dispositions']))
                <div>
                    <p class="text-xs font-semibold text-zinc-500 mb-2 uppercase tracking-wide">By disposition</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach (array_slice($callKpis['dispositions'], 0, 12) as $row)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 text-sm">
                                <span class="text-zinc-700 truncate pr-2">{{ $row['label'] }}</span>
                                <span class="font-semibold text-zinc-900">{{ number_format($row['count']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        @if (($counts['ready_to_distribute'] ?? 0) > 0)
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="app-card app-card-padded space-y-3">
                    <h3 class="app-section-title">Assign to team lead</h3>
                    <form method="POST" action="{{ route('admin.campaigns.assign-team-lead', $campaign) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                        @csrf
                        @if ($workflowId)<input type="hidden" name="workflow_id" value="{{ $workflowId }}">@endif
                        <div class="app-field">
                            <label class="app-label">Team lead</label>
                            <select name="team_lead_id" class="app-input" required @disabled(($setterTeamLeads ?? collect())->isEmpty())>
                                <option value="">Select team lead…</option>
                                @foreach ($setterTeamLeads ?? [] as $teamLead)
                                    <option value="{{ $teamLead->id }}">{{ $teamLead->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="app-field">
                            <label class="app-label">Number of leads (max {{ number_format($counts['ready_to_distribute']) }})</label>
                            <input type="number" name="lead_count" class="app-input" min="1" max="{{ max(1, $counts['ready_to_distribute']) }}" value="{{ min($counts['ready_to_distribute'], max(1, $counts['ready_to_distribute'])) }}" required>
                        </div>
                        <button type="submit" class="app-btn app-btn-primary app-btn-sm" @disabled(($setterTeamLeads ?? collect())->isEmpty())>Assign to team</button>
                    </form>
                    @if (($setterTeamLeads ?? collect())->isEmpty())
                        <p class="text-xs text-amber-700">Add an Appointment Setter Team Lead in User Management first.</p>
                    @endif
                </div>
                <div class="app-card app-card-padded space-y-3">
                    <h3 class="app-section-title">Batch assign setters</h3>
                    <form method="POST" action="{{ route('admin.campaigns.distribute', $campaign) }}" class="space-y-3">
                        @csrf
                        @if ($workflowId)<input type="hidden" name="workflow_id" value="{{ $workflowId }}">@endif
                        <div class="flex flex-wrap gap-2">
                            @foreach ($team as $member)
                                <label class="app-member-chip">
                                    <input type="checkbox" name="distribution_users[]" value="{{ $member->id }}" checked>
                                    <span class="app-member-chip-name">{{ $member->name }}</span>
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" class="app-btn app-btn-primary app-btn-sm">Distribute</button>
                    </form>
                </div>
            </div>
        @endif

        <form method="GET" class="app-card app-card-padded flex flex-wrap gap-3 items-end">
            <div class="app-field">
                <label class="app-label">Import file</label>
                <select name="workflow_id" class="app-input">
                    <option value="">All imports</option>
                    @foreach ($workflows as $wf)
                        <option value="{{ $wf->id }}" @selected($workflowId === $wf->id)>{{ $wf->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="app-field">
                <label class="app-label">Status</label>
                <select name="status" class="app-input">
                    <option value="">All</option>
                    @foreach (['imported', 'enriched', 'failed', 'completed'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Filter</button>
        </form>

        <div class="app-card app-card-padded">
            <x-data-table :paginator="$leads" min-width="720px">
                <table>
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Campaign</th>
                            <th>Import</th>
                            <th>Phone</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($leads as $lead)
                            <tr>
                                <td>
                                    <a href="{{ LeadRoute::show($lead, true) }}" class="font-bold hover:underline">{{ $lead->business_name }}</a>
                                </td>
                                <td>@include('partials.campaign-chip', ['campaign' => $lead->campaign ?? $campaign, 'compact' => true])</td>
                                <td>{{ $lead->workflow?->name ?? '—' }}</td>
                                <td>{{ $lead->input_phone ?: '—' }}</td>
                                <td><x-lead-pipeline-badge :status="$lead->status" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-8 text-zinc-500">No leads in this campaign.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </div>
    </div>
@endsection
