@extends('layouts.portal')

@section('title', 'Setter Team')

@section('content')
    <div class="app-page space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="app-page-title">Appointment Setter Team</h1>
                <p class="app-page-subtitle">Assign enriched leads to setters and monitor team progress.</p>
            </div>
            <button type="button" class="app-btn app-btn-primary" data-assign-leads-open>
                Assign leads
            </button>
        </div>

        @include('pipeline.partials.dashboard-widgets', ['dashboard' => $dashboard ?? []])

        @if (($unassignedLeads ?? 0) > 0)
            <div class="app-alert app-alert-info">
                <p class="app-alert-title">{{ number_format($unassignedLeads) }} unassigned lead(s) ready for distribution</p>
                <p class="app-alert-desc">Use Assign leads to choose how many go to each setter.</p>
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($teamMetrics as $metric)
                @php
                    $isActive = (string) request('setter') === (string) $metric['user']->id;
                @endphp
                <a href="{{ route('portal.setter-team.dashboard', array_filter(['setter' => $metric['user']->id, 'search' => request('search'), 'phase' => request('phase')])) }}"
                    class="app-card app-card-padded block transition hover:border-indigo-200 {{ $isActive ? 'ring-2 ring-indigo-500 border-indigo-200' : '' }}">
                    <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ $metric['user']->name }}</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ $metric['active_leads'] }}</p>
                    <p class="text-xs text-zinc-500">Active leads</p>
                    <p class="text-sm text-emerald-700 font-semibold mt-2">{{ $metric['settled_leads'] }} settled</p>
                </a>
            @endforeach
        </div>

        <div class="app-card app-card-padded">
            <form method="GET" action="{{ route('portal.setter-team.dashboard') }}"
                class="flex flex-wrap items-end gap-3 mb-4">
                <div class="app-field flex-1 min-w-[200px]">
                    <label for="search" class="app-label">Search leads</label>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                        placeholder="Search business, owner, or email..." class="app-input">
                </div>
                <div class="app-field min-w-[150px]">
                    <label for="setter" class="app-label">Setter</label>
                    <select name="setter" id="setter" class="app-input">
                        <option value="">All setters</option>
                        @foreach ($setters ?? [] as $setter)
                            <option value="{{ $setter->id }}" @selected((string) request('setter') === (string) $setter->id)>{{ $setter->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="app-btn app-btn-primary">Search</button>
                @if (request()->filled('search') || request()->filled('setter') || request()->filled('phase'))
                    <a href="{{ route('portal.setter-team.dashboard') }}" class="app-btn app-btn-secondary">Clear</a>
                @endif
            </form>

            @include('pipeline.partials.leads-table', [
                'leads' => $leads,
                'statusColumn' => 'both',
                'showAssignee' => true,
                'readOnly' => true,
            ])
        </div>
    </div>

    @include('pipeline.partials.assign-leads-modal', [
        'setters' => $setters ?? collect(),
        'teamMetrics' => $teamMetrics,
        'unassignedLeads' => $unassignedLeads ?? 0,
        'formAction' => route('portal.setter-team.assign-leads'),
    ])
@endsection
