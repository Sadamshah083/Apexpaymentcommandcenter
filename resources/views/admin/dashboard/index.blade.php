@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('content')
<div id="admin-dashboard-root"
    data-poll-url="{{ route('admin.dashboard.realtime-data') }}"
    data-dashboard-config='@json(['pipeline' => $pipeline, 'workflows' => array_values($workflows)])'
    hidden
    aria-hidden="true"></div>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@endpush
