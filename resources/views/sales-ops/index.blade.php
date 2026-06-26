@extends('layouts.admin')

@section('title', 'Sales Operations Command Center')

@section('content')
<div class="app-page space-y-8">
    <div class="app-page-header">
        <h1 class="app-page-title">Apex One Sales Operations</h1>
        <p class="app-page-subtitle">Pipeline visibility, SDR load, verification queue, and team performance for {{ $workspace->name }}.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="app-card app-card-padded">
            <p class="text-xs font-bold text-slate-400 uppercase">Active CRM Leads</p>
            <p id="workspace-sync-kpi-active-leads" class="text-3xl font-black text-slate-800 mt-1">{{ $overview['total_active_leads'] }}</p>
        </div>
        <div class="app-card app-card-padded">
            <p class="text-xs font-bold text-slate-400 uppercase">Awaiting Verification</p>
            <p id="workspace-sync-kpi-pending-verification" class="text-3xl font-black text-amber-600 mt-1">{{ $overview['pending_verification'] }}</p>
        </div>
        <div class="app-card app-card-padded">
            <p class="text-xs font-bold text-slate-400 uppercase">Reactivation Queue</p>
            <p id="workspace-sync-kpi-reactivation" class="text-3xl font-black text-indigo-600 mt-1">{{ $overview['reactivation_queue'] }}</p>
        </div>
        <div class="app-card app-card-padded">
            <p class="text-xs font-bold text-slate-400 uppercase">SDR Pool Cap</p>
            <p class="text-3xl font-black text-slate-800 mt-1">{{ config('sales_ops.leads_per_sdr', 500) }}<span class="text-sm font-semibold text-slate-400"> / SDR</span></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="app-card app-card-padded">
            <h2 class="text-lg font-bold text-slate-800 mb-4">Lead Tier Breakdown</h2>
            <div class="space-y-2">
                @foreach(config('sales_ops.lead_tiers', []) as $key => $tier)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">{{ $tier['label'] }}</span>
                        <span id="workspace-sync-tier-{{ $key }}" class="font-bold">{{ $overview['tier_breakdown'][$key] ?? 0 }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="app-card app-card-padded">
            <h2 class="text-lg font-bold text-slate-800 mb-4">CRM Stage Breakdown</h2>
            <div class="space-y-2">
                @foreach(\App\Support\SalesOps::crmStages() as $key => $label)
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600">{{ $label }}</span>
                        <span id="workspace-sync-stage-{{ $key }}" class="font-bold">{{ $overview['stage_breakdown'][$key] ?? 0 }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="app-card app-card-padded">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-slate-800">Weekly Leaderboard</h2>
            <a href="{{ route('admin.sales-ops.performance') }}" class="text-sm font-semibold text-indigo-600">Full report →</a>
        </div>
        <x-data-table :paginator="null" min-width="700px">
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Rep</th>
                        <th>Role</th>
                        <th>Dials</th>
                        <th>Conversations</th>
                        <th>Discoveries</th>
                        <th>Meetings</th>
                        <th>Funded</th>
                    </tr>
                </thead>
                <tbody id="workspace-sync-leaderboard-body">
                    @foreach($leaderboard as $index => $row)
                        <tr>
                            <td class="font-bold">#{{ $index + 1 }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['role'] }}</td>
                            <td>{{ $row['dials'] }}</td>
                            <td>{{ $row['conversations'] }}</td>
                            <td>{{ $row['discoveries'] }}</td>
                            <td>{{ $row['meetings'] }}</td>
                            <td>{{ $row['deals_funded'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-data-table>
    </div>
</div>
@endsection
