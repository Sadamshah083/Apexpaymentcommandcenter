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

        <!-- Metrics Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($teamMetrics as $metric)
                <div class="app-card app-card-padded">
                    <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ $metric['user']->name }}</p>
                    <div class="flex justify-between items-baseline mt-1">
                        <p class="text-2xl font-bold text-zinc-900">{{ $metric['active_leads'] }}</p>
                        <p class="text-sm font-semibold text-emerald-700">{{ $metric['sales_made'] }} sold</p>
                    </div>
                    <p class="text-xs text-zinc-500 mt-2">Active leads / Total closed: {{ $metric['total_closed'] }}</p>
                </div>
            @endforeach
        </div>

        <!-- Filters & Leads -->
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
                        <option value="with_closer" {{ request('phase') === 'with_closer' ? 'selected' : '' }}>With Closer
                        </option>
                        <option value="closed" {{ request('phase') === 'closed' ? 'selected' : '' }}>Closed</option>
                    </select>
                </div>
                <button type="submit" class="app-btn app-btn-primary">Search</button>
                @if (request()->filled('search') || request()->filled('phase'))
                    <a href="{{ route('portal.closer-team.dashboard') }}" class="app-btn app-btn-secondary">Clear</a>
                @endif
            </form>

            @include('pipeline.partials.leads-table', [
                'leads' => $leads,
                'statusColumn' => 'closer',
                'showAssignee' => true,
                'readOnly' => true,
            ])
        </div>
    </div>
@endsection
