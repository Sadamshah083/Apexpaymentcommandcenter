@extends('layouts.portal')

@section('title', 'Closer Team Dashboard')

@section('content')
    <div class="app-page space-y-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="app-page-title">Closer Team Dashboard</h1>
                <p class="app-page-subtitle">Monitor closer performance, lead loads, and close rates.</p>
            </div>
            <div>
                <a href="{{ route('portal.closer-team.queue') }}" class="app-btn app-btn-primary">
                    View Handoff Queue
                </a>
            </div>
        </div>

        @include('pipeline.partials.dashboard-widgets', ['dashboard' => $dashboard ?? []])

        @include('pipeline.partials.campaigns-overview', ['campaigns' => $campaigns ?? collect(), 'dashboard' => $dashboard ?? []])

        @include('pipeline.partials.portal-sync-context', ['portalView' => 'closer_team', 'leads' => $leads])

        @include('pipeline.partials.detail-focus-banner', ['focus' => $focus ?? null])

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($teamMetrics as $metric)
                @php
                    $isActive = (string) request('closer') === (string) $metric['user']->id;
                @endphp
                <a href="{{ route('portal.closer-team.dashboard', array_filter(['closer' => $metric['user']->id, 'search' => request('search'), 'phase' => request('phase')])) }}"
                    class="app-card app-card-padded block transition hover:border-indigo-200 {{ $isActive ? 'ring-2 ring-indigo-500 border-indigo-200' : '' }}"
                    data-team-member-id="{{ $metric['user']->id }}">
                    <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ $metric['user']->name }}</p>
                    <div class="flex justify-between items-baseline mt-1">
                        <p class="text-2xl font-bold text-zinc-900" data-team-metric="active">{{ $metric['active_leads'] }}</p>
                        <p class="text-sm font-semibold text-emerald-700"><span data-team-metric="sold">{{ $metric['sales_made'] }}</span> sold</p>
                    </div>
                    <p class="text-xs text-zinc-500 mt-2">Active leads / Total closed: <span data-team-metric="closed">{{ $metric['total_closed'] }}</span></p>
                </a>
            @endforeach
        </div>

        <div class="app-card app-card-padded">
            <form method="GET" action="{{ route('portal.closer-team.dashboard') }}"
                class="flex flex-wrap items-end gap-3 mb-4">
                <div class="app-field flex-1 min-w-[200px]">
                    <label for="search" class="app-label">Search leads</label>
                    <input type="text" name="search" id="search" value="{{ request('search') }}"
                        placeholder="Search business, owner, or email..." class="app-input">
                </div>
                <div class="app-field min-w-[150px]">
                    <label for="phase" class="app-label">Pipeline Phase</label>
                    <select name="phase" id="phase" class="app-input">
                        <option value="">All Phases</option>
                        <option value="with_closer" @selected(request('phase') === 'with_closer')>With Closer</option>
                        <option value="closed" @selected(request('phase') === 'closed')>Closed</option>
                    </select>
                </div>
                <div class="app-field min-w-[150px]">
                    <label for="closer" class="app-label">Closer</label>
                    <select name="closer" id="closer" class="app-input">
                        <option value="">All closers</option>
                        @foreach ($closers ?? [] as $closer)
                            <option value="{{ $closer->id }}" @selected((string) request('closer') === (string) $closer->id)>{{ $closer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="app-field min-w-[150px]">
                    <label for="campaign" class="app-label">Campaign</label>
                    <select name="campaign" id="campaign" class="app-input">
                        <option value="">All campaigns</option>
                        @foreach ($campaigns ?? [] as $campaign)
                            <option value="{{ $campaign->id }}" @selected((string) request('campaign') === (string) $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="app-btn app-btn-primary">Search</button>
                @if (request()->filled('search') || request()->filled('phase') || request()->filled('closer') || request()->filled('campaign'))
                    <a href="{{ route('portal.closer-team.dashboard') }}" class="app-btn app-btn-secondary">Clear</a>
                @endif
            </form>

            @include('pipeline.partials.leads-table', [
                'leads' => $leads,
                'statusColumn' => 'closer',
                'showAssignee' => true,
                'readOnly' => true,
                'liveSync' => true,
            ])
        </div>
    </div>
@endsection
