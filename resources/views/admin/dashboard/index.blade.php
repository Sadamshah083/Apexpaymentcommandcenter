@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
<div class="mb-8 flex items-center justify-between">
    <div>
        <h2 class="text-3xl font-bold tracking-tight text-slate-800">Admin Dashboard</h2>
        <p class="text-slate-500">Workspace: <span class="font-semibold text-slate-700">{{ $workspace->name }}</span> · Live performance tracking and lead metrics.</p>
    </div>
    <div class="flex items-center gap-2 bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-full text-xs font-semibold">
        <span class="relative flex h-2 w-2">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
            <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
        </span>
        Live Auto-syncing
    </div>
</div>

@if (!empty($ops))
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <a href="{{ route('admin.sales-ops.index') }}" class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 hover:border-indigo-200 transition">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Active CRM Leads</p>
        <p id="ops-active-leads" class="text-3xl font-extrabold text-slate-800 mt-1">{{ $ops['overview']['total_active_leads'] ?? 0 }}</p>
    </a>
    <a href="{{ route('admin.sales-ops.index') }}" class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 hover:border-amber-200 transition">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Awaiting Verification</p>
        <p id="ops-pending-verification" class="text-3xl font-extrabold text-amber-600 mt-1">{{ $ops['overview']['pending_verification'] ?? 0 }}</p>
    </a>
    <a href="{{ route('admin.sales-ops.reactivation') }}" class="bg-white rounded-xl shadow-sm border border-slate-100 p-5 hover:border-indigo-200 transition">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Reactivation Queue</p>
        <p id="ops-reactivation" class="text-3xl font-extrabold text-slate-800 mt-1">{{ $ops['overview']['reactivation_queue'] ?? 0 }}</p>
    </a>
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-5">
        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Handoff Queue</p>
        <p id="ops-handoff-queue" class="text-3xl font-extrabold text-indigo-600 mt-1">{{ $ops['handoff_queue'] ?? 0 }}</p>
        <p class="text-xs text-slate-500 mt-1">Settled, awaiting closer</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-lg font-bold text-slate-800 mb-4">Today's team activity</h3>
        <div class="grid grid-cols-2 gap-4">
            @foreach (['dials' => 'Dials', 'conversations' => 'Conversations', 'discoveries' => 'Discoveries', 'meetings' => 'Meetings booked'] as $key => $label)
                <div>
                    <p class="text-xs font-semibold text-slate-400 uppercase">{{ $label }}</p>
                    <p id="ops-today-{{ $key }}" class="text-2xl font-extrabold text-slate-800 mt-1">{{ $ops['today_activity'][$key] ?? 0 }}</p>
                </div>
            @endforeach
        </div>
        @if (($ops['at_capacity_setters'] ?? 0) > 0)
            <p class="text-sm text-amber-700 mt-4 font-medium">{{ $ops['at_capacity_setters'] }} setter(s) at book capacity — <a href="{{ route('admin.sales-ops.distribution') }}" class="text-indigo-600 hover:underline">view load</a></p>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-slate-800">Weekly leaderboard</h3>
            <a href="{{ route('admin.sales-ops.performance') }}" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800">Full report</a>
        </div>
        <div id="ops-leaderboard" class="space-y-2">
            @forelse ($ops['leaderboard'] ?? [] as $i => $row)
                <div class="flex justify-between items-center text-sm py-1">
                    <span class="text-slate-700"><span class="font-bold text-slate-400 mr-2">#{{ $i + 1 }}</span>{{ $row['name'] }} <span class="text-slate-400">· {{ $row['role'] }}</span></span>
                    <span class="font-semibold text-slate-600">{{ $row['dials'] }} d · {{ $row['meetings'] }} m · {{ $row['deals_funded'] }} funded</span>
                </div>
            @empty
                <p class="text-sm text-slate-500">No activity logged this week yet.</p>
            @endforelse
        </div>
    </div>
</div>
@endif

<!-- Main Stats Section -->
<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <!-- Pipeline Block -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2">
            <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
            </span>
            PIPELINE (all-time)
        </h3>
        <div class="divide-y divide-slate-100">
            <a href="{{ route('admin.workflows.index') }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">Total Leads</span>
                <span id="stat-total_leads" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['total_leads'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'imported']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">New</span>
                <span id="stat-new" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['new'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'enriched']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">Qualified</span>
                <span id="stat-qualified" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['qualified'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'appointment_settled']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">Booked</span>
                <span id="stat-booked" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['booked'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'with_closer']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">Showed</span>
                <span id="stat-showed" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['showed'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'closed']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-green-600">Closed (Won)</span>
                <span id="stat-closed_won" class="font-extrabold text-green-600 text-lg">{{ $pipeline['closed_won'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'with_setter']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-indigo-600">Not Now</span>
                <span id="stat-not_now" class="font-extrabold text-slate-800 text-lg">{{ $pipeline['not_now'] }}</span>
            </a>
            <a href="{{ route('admin.workflows.index', ['phase' => 'closed', 'search' => 'closed_lost']) }}" class="flex justify-between py-2.5 hover:bg-slate-50 px-2 rounded-lg transition group">
                <span class="text-slate-500 font-medium group-hover:text-red-500">Dead</span>
                <span id="stat-dead" class="font-extrabold text-red-500 text-lg">{{ $pipeline['dead'] }}</span>
            </a>
        </div>
    </div>

    <!-- Conversion Rates Block -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 flex flex-col justify-between">
        <div>
            <h3 class="text-lg font-bold text-slate-800 mb-6 flex items-center gap-2">
                <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                </span>
                CONVERSION RATES
            </h3>
            <div class="divide-y divide-slate-100">
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Book &rarr; Show Rate</span>
                    <span id="stat-book_to_show" class="font-extrabold text-slate-800 text-lg">{{ $conversion_rates['book_to_show_rate'] !== null ? $conversion_rates['book_to_show_rate'].'%' : '-' }}</span>
                </div>
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Show &rarr; Close Rate</span>
                    <span id="stat-show_to_close" class="font-extrabold text-slate-800 text-lg">{{ $conversion_rates['show_to_close_rate'] !== null ? $conversion_rates['show_to_close_rate'].'%' : '-' }}</span>
                </div>
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Overall Close Rate</span>
                    <span id="stat-overall_close" class="font-extrabold text-green-600 text-lg">{{ $conversion_rates['overall_close_rate'] !== null ? $conversion_rates['overall_close_rate'].'%' : '-' }}</span>
                </div>
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Avg Closed Deal Volume</span>
                    <span id="stat-avg_deal_volume" class="font-extrabold text-slate-800 text-lg">${{ number_format($conversion_rates['avg_closed_volume'], 2) }}</span>
                </div>
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Total Team Dials (period)</span>
                    <span id="stat-total_dials" class="font-extrabold text-slate-800 text-lg">{{ $conversion_rates['total_dials'] }}</span>
                </div>
                <div class="flex justify-between py-3 px-2">
                    <span class="text-slate-500 font-medium">Total Team Closes (period)</span>
                    <span id="stat-total_closes" class="font-extrabold text-slate-800 text-lg">{{ $conversion_rates['total_closes'] }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Leaders Tables Section -->
<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <!-- Leads by Fronter Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
            <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
            </span>
            LEADS BY FRONTER (Setters)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase">
                        <th class="py-3 font-medium">Fronter (Setter)</th>
                        <th class="py-3 text-right font-medium">Leads Logged</th>
                    </tr>
                </thead>
                <tbody id="setters-table-body" class="divide-y divide-slate-50 text-sm text-slate-600">
                    @forelse ($setters as $setter)
                        <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='{{ route('admin.workflows.index', ['assigned_user_id' => $setter['id']]) }}'">
                            <td class="py-3.5 font-medium text-slate-800">
                                <a href="{{ route('admin.workflows.index', ['assigned_user_id' => $setter['id']]) }}" class="text-indigo-600 hover:text-indigo-900">{{ $setter['name'] }}</a>
                            </td>
                            <td class="py-3.5 text-right font-extrabold text-slate-800">{{ $setter['leads_logged'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="py-4 text-center text-slate-400">No active setters found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Leads by Closer Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-lg font-bold text-slate-800 mb-4 flex items-center gap-2">
            <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                </svg>
            </span>
            LEADS BY CLOSER
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase">
                        <th class="py-3 font-medium">Closer</th>
                        <th class="py-3 text-right font-medium">Deals Closed</th>
                    </tr>
                </thead>
                <tbody id="closers-table-body" class="divide-y divide-slate-50 text-sm text-slate-600">
                    @forelse ($closers as $closer)
                        <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='{{ route('admin.workflows.index', ['assigned_user_id' => $closer['id']]) }}'">
                            <td class="py-3.5 font-medium text-slate-800">
                                <a href="{{ route('admin.workflows.index', ['assigned_user_id' => $closer['id']]) }}" class="text-indigo-600 hover:text-indigo-900">{{ $closer['name'] }}</a>
                            </td>
                            <td class="py-3.5 text-right font-extrabold text-green-600">{{ $closer['deals_closed'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="py-4 text-center text-slate-400">No active closers found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Data Visualization & Analytics Graphs Section -->
<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <!-- Funnel Visualization Chart -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 lg:col-span-2">
        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4">Pipeline Conversion Funnel</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="pipelineFunnelChart"></canvas>
        </div>
    </div>

    <!-- Data Files Performance Chart -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6">
        <h3 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4">Data Files Performance (Recent)</h3>
        <div class="relative" style="height: 300px;">
            <canvas id="workflowsPerformanceChart"></canvas>
        </div>
    </div>
</div>

<!-- Data Files (Workflows) Performance Table -->
<div class="bg-white rounded-xl shadow-sm border border-slate-100 p-6 mb-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
            <span class="p-1.5 bg-indigo-50 text-indigo-600 rounded-lg">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
            </span>
            DATA FILES & WORKFLOW PERFORMANCE
        </h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-slate-100 text-xs font-semibold text-slate-400 uppercase">
                    <th class="py-3 font-medium">File Name</th>
                    <th class="py-3 font-medium">Imported At</th>
                    <th class="py-3 text-right font-medium">Total Leads</th>
                    <th class="py-3 text-right font-medium">Enriched Success</th>
                    <th class="py-3 text-right font-medium">Failed</th>
                    <th class="py-3 text-right font-medium">Closed Won</th>
                    <th class="py-3 text-right font-medium">Enrichment %</th>
                    <th class="py-3 text-right font-medium">Close %</th>
                    <th class="py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody id="workflows-table-body" class="divide-y divide-slate-50 text-sm text-slate-600">
                @forelse ($workflows as $wf)
                    <tr class="hover:bg-slate-50 transition">
                        <td class="py-3.5 font-semibold text-slate-800">
                            <a href="{{ route('admin.workflows.show', $wf['id']) }}" class="text-indigo-600 hover:text-indigo-900">{{ $wf['name'] }}</a>
                            <span class="block text-xs font-normal text-slate-400">{{ $wf['filename'] }}</span>
                        </td>
                        <td class="py-3.5 text-slate-500">{{ $wf['created_at'] }}</td>
                        <td class="py-3.5 text-right font-semibold text-slate-800">{{ $wf['total_leads'] }}</td>
                        <td class="py-3.5 text-right text-emerald-600 font-semibold">{{ $wf['enriched_leads'] }}</td>
                        <td class="py-3.5 text-right text-red-500 font-semibold">{{ $wf['failed_leads'] }}</td>
                        <td class="py-3.5 text-right text-green-600 font-semibold">{{ $wf['closed_deals'] }}</td>
                        <td class="py-3.5 text-right">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                {{ $wf['enrichment_rate'] }}%
                            </span>
                        </td>
                        <td class="py-3.5 text-right">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">
                                {{ $wf['close_rate'] }}%
                            </span>
                        </td>
                        <td class="py-3.5 text-right">
                            <a href="{{ route('admin.workflows.show', $wf['id']) }}" class="app-btn app-btn-secondary app-btn-sm !py-1 !px-2">
                                Track file
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="py-4 text-center text-slate-400">No workflow files uploaded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection

@push('scripts')
<!-- Load Chart.js from secure CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize Charts
        const funnelCtx = document.getElementById('pipelineFunnelChart').getContext('2d');
        const workflowsCtx = document.getElementById('workflowsPerformanceChart').getContext('2d');

        // Funnel Chart configuration (Horizontal Bar Chart simulating a funnel)
        const funnelChart = new Chart(funnelCtx, {
            type: 'bar',
            data: {
                labels: ['Total Leads', 'New', 'Qualified', 'Booked', 'Showed', 'Closed (Won)'],
                datasets: [{
                    label: 'Leads',
                    data: [
                        {{ $pipeline['total_leads'] }},
                        {{ $pipeline['new'] }},
                        {{ $pipeline['qualified'] }},
                        {{ $pipeline['booked'] }},
                        {{ $pipeline['showed'] }},
                        {{ $pipeline['closed_won'] }}
                    ],
                    backgroundColor: [
                        'rgba(99, 102, 241, 0.85)',  // Indigo
                        'rgba(59, 130, 246, 0.85)',  // Blue
                        'rgba(14, 165, 233, 0.85)',  // Sky
                        'rgba(245, 158, 11, 0.85)',  // Amber
                        'rgba(236, 72, 153, 0.85)',  // Pink
                        'rgba(34, 197, 94, 0.85)'    // Green
                    ],
                    borderRadius: 6,
                    borderWidth: 0,
                    barPercentage: 0.6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { precision: 0 }
                    },
                    y: {
                        grid: { display: false }
                    }
                }
            }
        });

        // Data Files Performance Bar Chart (comparing Top Workflows performance)
        const workflowLabels = [];
        const workflowTotals = [];
        const workflowEnriched = [];
        const workflowClosed = [];

        @foreach (array_slice($workflows, 0, 5) as $wf)
            workflowLabels.push("{{ $wf['name'] }}");
            workflowTotals.push({{ $wf['total_leads'] }});
            workflowEnriched.push({{ $wf['enriched_leads'] }});
            workflowClosed.push({{ $wf['closed_deals'] }});
        @endforeach

        const workflowsChart = new Chart(workflowsCtx, {
            type: 'bar',
            data: {
                labels: workflowLabels,
                datasets: [
                    {
                        label: 'Total Leads',
                        data: workflowTotals,
                        backgroundColor: 'rgba(165, 180, 252, 0.8)', // Light Indigo
                        borderRadius: 4
                    },
                    {
                        label: 'Enriched',
                        data: workflowEnriched,
                        backgroundColor: 'rgba(99, 102, 241, 0.85)', // Indigo
                        borderRadius: 4
                    },
                    {
                        label: 'Closed',
                        data: workflowClosed,
                        backgroundColor: 'rgba(34, 197, 94, 0.85)', // Green
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 12 }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { display: false }, ticks: { precision: 0 } }
                }
            }
        });

        // Real-time dashboard updates (stops on Turbo navigation)
        const pollEndpoint = "{{ route('admin.dashboard.realtime-data') }}";
        let stopDashboardPoll = null;

        function updateDashboardMetrics(data) {
            const setText = (id, value) => {
                const el = document.getElementById(id);
                if (el) {
                    el.innerText = value;
                }
            };

            setText('stat-total_leads', data.pipeline.total_leads);
            setText('stat-new', data.pipeline.new);
            setText('stat-qualified', data.pipeline.qualified);
            setText('stat-booked', data.pipeline.booked);
            setText('stat-showed', data.pipeline.showed);
            setText('stat-closed_won', data.pipeline.closed_won);
            setText('stat-not_now', data.pipeline.not_now);
            setText('stat-dead', data.pipeline.dead);

            setText('stat-book_to_show', data.conversion_rates.book_to_show_rate !== null ? data.conversion_rates.book_to_show_rate + '%' : '-');
            setText('stat-show_to_close', data.conversion_rates.show_to_close_rate !== null ? data.conversion_rates.show_to_close_rate + '%' : '-');
            setText('stat-overall_close', data.conversion_rates.overall_close_rate !== null ? data.conversion_rates.overall_close_rate + '%' : '-');
            setText('stat-avg_deal_volume', '$' + data.conversion_rates.avg_closed_volume.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
            setText('stat-total_dials', data.conversion_rates.total_dials);
            setText('stat-total_closes', data.conversion_rates.total_closes);

            if (data.ops) {
                setText('ops-active-leads', data.ops.overview?.total_active_leads ?? 0);
                setText('ops-pending-verification', data.ops.overview?.pending_verification ?? 0);
                setText('ops-reactivation', data.ops.overview?.reactivation_queue ?? 0);
                setText('ops-handoff-queue', data.ops.handoff_queue ?? 0);
                setText('ops-today-dials', data.ops.today_activity?.dials ?? 0);
                setText('ops-today-conversations', data.ops.today_activity?.conversations ?? 0);
                setText('ops-today-discoveries', data.ops.today_activity?.discoveries ?? 0);
                setText('ops-today-meetings', data.ops.today_activity?.meetings ?? 0);
            }

            if (!funnelChart) {
                return;
            }

            funnelChart.data.datasets[0].data = [
                        data.pipeline.total_leads,
                        data.pipeline.new,
                        data.pipeline.qualified,
                        data.pipeline.booked,
                        data.pipeline.showed,
                        data.pipeline.closed_won
            ];
            funnelChart.update();

            const settersBody = document.getElementById('setters-table-body');
            if (settersBody) {
                let settersHTML = '';
                    if (data.setters.length > 0) {
                        data.setters.forEach(setter => {
                            settersHTML += `
                                <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='/admin/workflows?assigned_user_id=${setter.id}'">
                                    <td class="py-3.5 font-medium text-slate-800">
                                        <a href="/admin/workflows?assigned_user_id=${setter.id}" class="text-indigo-600 hover:text-indigo-900">${setter.name}</a>
                                    </td>
                                    <td class="py-3.5 text-right font-extrabold text-slate-800">${setter.leads_logged}</td>
                                </tr>
                            `;
                        });
                    } else {
                        settersHTML = `<tr><td colspan="2" class="py-4 text-center text-slate-400">No active setters found.</td></tr>`;
                    }
                settersBody.innerHTML = settersHTML;
            }

            const closersBody = document.getElementById('closers-table-body');
            if (closersBody) {
                let closersHTML = '';
                    if (data.closers.length > 0) {
                        data.closers.forEach(closer => {
                            closersHTML += `
                                <tr class="hover:bg-slate-50 transition cursor-pointer" onclick="window.location='/admin/workflows?assigned_user_id=${closer.id}'">
                                    <td class="py-3.5 font-medium text-slate-800">
                                        <a href="/admin/workflows?assigned_user_id=${closer.id}" class="text-indigo-600 hover:text-indigo-900">${closer.name}</a>
                                    </td>
                                    <td class="py-3.5 text-right font-extrabold text-green-600">${closer.deals_closed}</td>
                                </tr>
                            `;
                        });
                    } else {
                        closersHTML = `<tr><td colspan="2" class="py-4 text-center text-slate-400">No active closers found.</td></tr>`;
                    }
                closersBody.innerHTML = closersHTML;
            }

            const workflowsBody = document.getElementById('workflows-table-body');
            if (workflowsBody) {
                let workflowsHTML = '';
                    const newLabels = [];
                    const newTotals = [];
                    const newEnriched = [];
                    const newClosed = [];

                    if (data.workflows.length > 0) {
                        data.workflows.forEach((wf, index) => {
                            // Collect data for top 5 charts
                            if (index < 5) {
                                newLabels.push(wf.name);
                                newTotals.push(wf.total_leads);
                                newEnriched.push(wf.enriched_leads);
                                newClosed.push(wf.closed_deals);
                            }

                            workflowsHTML += `
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-3.5 font-semibold text-slate-800">
                                        <a href="/admin/workflows/${wf.id}" class="text-indigo-600 hover:text-indigo-900">${wf.name}</a>
                                        <span class="block text-xs font-normal text-slate-400">${wf.filename || ''}</span>
                                    </td>
                                    <td class="py-3.5 text-slate-500">${wf.created_at}</td>
                                    <td class="py-3.5 text-right font-semibold text-slate-800">${wf.total_leads}</td>
                                    <td class="py-3.5 text-right text-emerald-600 font-semibold">${wf.enriched_leads}</td>
                                    <td class="py-3.5 text-right text-red-500 font-semibold">${wf.failed_leads}</td>
                                    <td class="py-3.5 text-right text-green-600 font-semibold">${wf.closed_deals}</td>
                                    <td class="py-3.5 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                            ${wf.enrichment_rate}%
                                        </span>
                                    </td>
                                    <td class="py-3.5 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700">
                                            ${wf.close_rate}%
                                        </span>
                                    </td>
                                    <td class="py-3.5 text-right">
                                        <a href="/admin/workflows/${wf.id}" class="app-btn app-btn-secondary app-btn-sm !py-1 !px-2">Track file</a>
                                    </td>
                                </tr>
                            `;
                        });
                    } else {
                        workflowsHTML = `<tr><td colspan="9" class="py-4 text-center text-slate-400">No workflow files uploaded yet.</td></tr>`;
                    }
                workflowsBody.innerHTML = workflowsHTML;

                if (workflowsChart) {
                    workflowsChart.data.labels = newLabels;
                    workflowsChart.data.datasets[0].data = newTotals;
                    workflowsChart.data.datasets[1].data = newEnriched;
                    workflowsChart.data.datasets[2].data = newClosed;
                    workflowsChart.update();
                }
            }
        }

        if (window.startProgressPoll) {
            stopDashboardPoll = window.startProgressPoll(pollEndpoint, (data) => {
                updateDashboardMetrics(data);
                return true;
            }, { activeMs: 5000, hiddenMs: 15000 });
        }

        document.addEventListener('turbo:before-cache', () => {
            stopDashboardPoll?.();
            stopDashboardPoll = null;
        }, { once: true });
    });
</script>
@endpush
