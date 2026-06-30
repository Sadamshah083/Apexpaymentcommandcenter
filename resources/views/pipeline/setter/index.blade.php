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
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search leads…" class="app-input app-input-sm">
            <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Search</button>
        </form>
    </div>

    @include('pipeline.partials.leads-table', [
        'leads' => $leads,
        'statusColumn' => 'setter',
        'showAssignee' => false,
    ])
</div>
@endsection
