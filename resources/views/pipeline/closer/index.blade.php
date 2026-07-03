@extends('layouts.portal')

@section('title', 'My Closer Leads')

@section('content')
    <div class="app-page space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="app-page-title">My Closer Leads</h1>
                <p class="app-page-subtitle">Work assigned leads through to sale or closed lost.</p>
            </div>
            <form method="GET" class="flex gap-2">
                <input type="search" name="search" value="{{ request('search') }}" placeholder="Search leads…"
                    class="app-input app-input-sm">
                <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Search</button>
            </form>
        </div>

        @include('pipeline.partials.dashboard-widgets', ['dashboard' => $dashboard ?? []])

        @include('pipeline.partials.detail-focus-banner', ['focus' => $focus ?? null])

        <div id="portal-leads-section">
        @include('pipeline.partials.leads-table', [
            'leads' => $leads,
            'statusColumn' => 'closer',
            'showAssignee' => false,
            'showSetterNotes' => true,
        ])
        </div>
    </div>
@endsection
