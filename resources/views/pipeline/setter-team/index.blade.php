@extends('layouts.portal')

@section('title', 'Setter Team')

@section('content')
    <div class="app-page space-y-6">
        <div>
            <h1 class="app-page-title">Appointment Setter Team</h1>
            <p class="app-page-subtitle">Read-only view of your team's leads and metrics.</p>
        </div>

        @include('pipeline.partials.dashboard-widgets', ['dashboard' => $dashboard ?? []])

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach ($teamMetrics as $metric)
                <div class="app-card app-card-padded">
                    <p class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ $metric['user']->name }}</p>
                    <p class="text-2xl font-bold text-zinc-900 mt-1">{{ $metric['active_leads'] }}</p>
                    <p class="text-xs text-zinc-500">Active leads</p>
                    <p class="text-sm text-emerald-700 font-semibold mt-2">{{ $metric['settled_leads'] }} settled</p>
                </div>
            @endforeach
        </div>

        @include('pipeline.partials.leads-table', [
            'leads' => $leads,
            'statusColumn' => 'both',
            'showAssignee' => true,
            'readOnly' => true,
        ])
    </div>
@endsection
