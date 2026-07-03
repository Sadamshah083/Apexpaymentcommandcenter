@extends('layouts.portal')

@section('title', 'My Leads')

@section('content')
    <div class="app-page space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="app-page-title">My Leads</h1>
                <p class="app-page-subtitle">Appointment setter queue — update status until appointment is settled.</p>
            </div>
            <form method="GET" class="flex gap-2">
                @foreach (request()->only(['focus', 'tier', 'status', 'member']) as $key => $value)
                    @if (filled($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Search leads…"
                    class="app-input app-input-sm">
                <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Search</button>
            </form>
        </div>

        @include('pipeline.partials.dashboard-widgets', ['dashboard' => $dashboard ?? []])

        @include('pipeline.partials.portal-sync-context', ['portalView' => 'setter', 'leads' => $leads])

        @include('pipeline.partials.detail-focus-banner', ['focus' => $focus ?? null])

        <div id="portal-leads-section">
        @include('pipeline.partials.leads-table', [
            'leads' => $leads,
            'statusColumn' => 'setter',
            'showAssignee' => false,
            'editableSetterStatus' => true,
            'setterStatuses' => $setterStatuses,
            'liveSync' => true,
        ])
        </div>
    </div>
@endsection
