@extends('layouts.portal')

@section('title', 'Daily Performance')

@section('content')
<div class="app-page space-y-8">
    <div>
        <h1 class="app-page-title">SDR Daily Performance</h1>
        <p class="app-page-subtitle">Track progress against Apex One activity standards for {{ now()->format('l, M j') }}.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach(['dials' => 'Outbound Dials', 'conversations' => 'Live Conversations', 'decision_maker_contacts' => 'Decision-Maker Contacts', 'discoveries' => 'Discoveries'] as $key => $label)
            @php $metric = $daily[$key]; @endphp
            <div class="app-card app-card-padded">
                <p class="text-xs font-bold text-slate-400 uppercase">{{ $label }}</p>
                <p class="text-2xl font-black text-slate-800 mt-1">
                    <span id="workspace-sync-metric-{{ $key }}-actual">{{ $metric['actual'] }}</span>
                    <span class="text-sm font-semibold text-slate-400">/ <span id="workspace-sync-metric-{{ $key }}-target">{{ $metric['target'] }}</span></span>
                </p>
                <div class="w-full h-2 bg-slate-100 rounded-full mt-3 overflow-hidden">
                    <div id="workspace-sync-metric-{{ $key }}-bar" class="h-full bg-indigo-600 transition-all duration-300" style="width: {{ $metric['pct'] }}%"></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="app-card app-card-padded">
        <h2 class="text-lg font-bold text-slate-800 mb-4">Weekly Targets</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <p class="text-sm text-slate-600">Discoveries this week</p>
                <p class="text-xl font-bold"><span id="workspace-sync-weekly-discoveries">{{ $weekly['discoveries']['actual'] }} / {{ $weekly['discoveries']['target'] }}</span></p>
                <div class="w-full h-2 bg-slate-100 rounded-full mt-2 overflow-hidden">
                    <div id="workspace-sync-weekly-discoveries-bar" class="h-full bg-indigo-600 transition-all duration-300" style="width: {{ $weekly['discoveries']['pct'] }}%"></div>
                </div>
            </div>
            <div>
                <p class="text-sm text-slate-600">Qualified meetings booked</p>
                <p class="text-xl font-bold"><span id="workspace-sync-weekly-meetings">{{ $weekly['qualified_meetings']['actual'] }} / {{ $weekly['qualified_meetings']['target'] }}</span></p>
                <div class="w-full h-2 bg-slate-100 rounded-full mt-2 overflow-hidden">
                    <div id="workspace-sync-weekly-meetings-bar" class="h-full bg-indigo-600 transition-all duration-300" style="width: {{ $weekly['qualified_meetings']['pct'] }}%"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="app-card app-card-padded bg-indigo-50 border border-indigo-100">
        <h3 class="font-bold text-indigo-900 text-sm">Multi-touch outreach reminder</h3>
        <p class="text-xs text-indigo-800 mt-1">After every successful conversation: send follow-up email, follow-up SMS, and LinkedIn connection when applicable. Log each touch from the lead record.</p>
    </div>
</div>
@endsection
