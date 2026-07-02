@extends('layouts.portal')

@section('title', 'SDR Daily Performance')

@section('content')
    <div class="app-page space-y-6">
        <x-page-header title="SDR Daily Performance"
            subtitle="Track progress against activity standards for {{ now()->format('l, M j') }}." />

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
            @foreach (['dials' => 'Outbound Dials', 'conversations' => 'Live Conversations', 'decision_maker_contacts' => 'Decision-Maker Contacts', 'discoveries' => 'Discoveries'] as $key => $label)
                @php $metric = $daily[$key]; @endphp
                <div class="app-card app-card-padded">
                    <p class="app-kpi-label">{{ $label }}</p>
                    <p class="app-kpi-value text-2xl">
                        <span id="workspace-sync-metric-{{ $key }}-actual">{{ $metric['actual'] }}</span>
                        <span class="text-sm font-semibold text-zinc-400">/ <span
                                id="workspace-sync-metric-{{ $key }}-target">{{ $metric['target'] }}</span></span>
                    </p>
                    <div class="app-progress-track mt-3">
                        <div id="workspace-sync-metric-{{ $key }}-bar" class="app-progress-fill"
                            style="width: {{ $metric['pct'] }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="app-card app-card-padded">
            <h2 class="app-section-title mb-4">Weekly targets</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-sm text-zinc-600">Discoveries this week</p>
                    <p class="text-xl font-bold text-zinc-900 mt-1"><span
                            id="workspace-sync-weekly-discoveries">{{ $weekly['discoveries']['actual'] }} /
                            {{ $weekly['discoveries']['target'] }}</span></p>
                    <div class="app-progress-track mt-2">
                        <div id="workspace-sync-weekly-discoveries-bar" class="app-progress-fill"
                            style="width: {{ $weekly['discoveries']['pct'] }}%"></div>
                    </div>
                </div>
                <div>
                    <p class="text-sm text-zinc-600">Qualified meetings booked</p>
                    <p class="text-xl font-bold text-zinc-900 mt-1"><span
                            id="workspace-sync-weekly-meetings">{{ $weekly['qualified_meetings']['actual'] }} /
                            {{ $weekly['qualified_meetings']['target'] }}</span></p>
                    <div class="app-progress-track mt-2">
                        <div id="workspace-sync-weekly-meetings-bar" class="app-progress-fill"
                            style="width: {{ $weekly['qualified_meetings']['pct'] }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-alert app-alert-warning">
            <p class="app-alert-title">Multi-touch outreach reminder</p>
            <p class="app-alert-desc">After every conversation: follow-up email, SMS, and LinkedIn when applicable. Log each
                touch from the lead record.</p>
        </div>
    </div>
@endsection
