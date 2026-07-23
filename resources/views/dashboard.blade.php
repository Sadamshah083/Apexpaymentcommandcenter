@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="app-page space-y-8">
        <div class="app-page-header">
            <h1 class="app-page-title text-3xl">Workspace Dashboard</h1>
            <p class="app-page-subtitle">Lead generation and deliverability toolkit.</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="app-card app-card-padded">
                <p class="text-sm text-zinc-500 font-semibold">Workspace Leads</p>
                <p class="text-3xl font-bold text-zinc-900 mt-1">{{ number_format($stats['workspace_leads_count']) }}</p>
                <p class="text-xs text-zinc-500 mt-1">{{ $stats['workflows_count'] }} workflows</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="text-sm text-zinc-500 font-semibold">Email Lists</p>
                <p class="text-3xl font-bold text-zinc-900 mt-1">{{ $stats['total_lists'] }}</p>
                <p class="text-xs text-zinc-500 mt-1">Total upload lists</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="text-sm text-zinc-500 font-semibold">Invalid Emails</p>
                <p class="text-3xl font-bold text-zinc-500 mt-1">{{ number_format($stats['invalid_emails']) }}</p>
                <p class="text-xs text-zinc-500 mt-1">Failed verification</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="text-sm text-zinc-500 font-semibold">Valid Emails</p>
                <p class="text-3xl font-bold text-emerald-600 mt-1">{{ number_format($stats['valid_emails']) }}</p>
                <p class="text-xs text-zinc-500 mt-1">Verified delivery-ready</p>
            </div>
            <div class="app-card app-card-padded">
                <p class="text-sm text-zinc-500 font-semibold">Queue Jobs</p>
                <p class="text-3xl font-bold mt-1 {{ $stats['pending_jobs'] > 0 ? 'text-amber-600' : 'text-zinc-900' }}">
                    {{ $stats['pending_jobs'] }}</p>
                @if ($stats['failed_jobs'] > 0)
                    <p class="text-xs text-rose-600 font-bold mt-1">{{ $stats['failed_jobs'] }} failed</p>
                @else
                    <p class="text-xs text-zinc-500 mt-1">Active background tasks</p>
                @endif
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <div class="app-card app-card-padded md:col-span-2">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-zinc-900">AI Lead Pipelines</h3>
                    <a href="{{ route('admin.workflows.create') }}"
                        class="text-xs font-bold text-zinc-500 hover:text-zinc-900 underline"
                        data-turbo-preload data-import-file-nav>New Pipeline</a>
                </div>
                <div class="space-y-3">
                    @forelse($recentWorkflows as $workflow)
                        <a href="{{ route('admin.workflows.show', $workflow) }}"
                            class="block p-3 bg-cream-50 hover:bg-cream-200 border border-warmgrey-200 rounded-xl transition-all">
                            <div class="flex justify-between items-center">
                                <span class="font-bold text-warmgrey-900 text-sm">{{ $workflow->name }}</span>
                                <span
                                    class="text-[10px] px-2 py-0.5 rounded-full font-extrabold uppercase tracking-wider
                            {{ $workflow->status === 'completed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' }}
                            {{ $workflow->status === 'failed' ? 'bg-rose-50 text-rose-700 border border-rose-200' : '' }}
                            {{ $workflow->status === 'extracting' ? 'bg-amber-50 text-amber-700 border border-amber-200 animate-pulse' : '' }}
                            {{ $workflow->status === 'mapping' ? 'bg-blue-50 text-blue-700 border border-blue-200' : '' }}
                            {{ $workflow->status === 'pending' ? 'bg-slate-100 text-slate-700 border border-slate-200' : '' }}
                        ">
                                    {{ $workflow->status }}
                                </span>
                            </div>
                            <span class="text-xs text-warmgrey-500 block mt-1">
                                {{ $workflow->processed_leads }} / {{ $workflow->total_leads }} processed ·
                                {{ $workflow->original_filename }}
                            </span>
                        </a>
                    @empty
                        <div class="text-center py-6 bg-cream-50 rounded-xl border border-dashed border-warmgrey-200">
                            <p class="text-warmgrey-500 text-sm">No active pipelines. <a
                                    href="{{ route('admin.workflows.create') }}"
                                    class="text-warmgrey-900 underline font-bold"
                                    data-turbo-preload data-import-file-nav>Upload spreadsheet</a> to start.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div class="app-hero flex flex-col justify-between">
                <div>
                    <h3 class="font-extrabold text-white text-base mb-2">Pipeline setup guide</h3>
                    <ol class="text-xs text-zinc-300 space-y-2.5 list-decimal list-inside leading-relaxed">
                        <li>Create or switch to your active workspace</li>
                        <li>Upload business listing (CSV or XLSX)</li>
                        <li>Confirm AI mappings & target headers</li>
                        <li>Run AI agent (scrapes & researches owner/phone)</li>
                        <li>Leads are distributed evenly to workspace team</li>
                    </ol>
                </div>
                <a href="{{ route('admin.workflows.index') }}"
                    class="app-btn app-btn-secondary bg-white text-zinc-900 hover:bg-zinc-100 block text-center mt-6">
                    Open dashboard
                </a>
            </div>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <div class="app-card app-card-padded">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-zinc-900">Recent Lists</h3>
                    <a href="{{ route('admin.lists.create') }}"
                        class="text-xs font-bold text-zinc-500 hover:text-zinc-900 underline">Upload</a>
                </div>
                <div class="space-y-2">
                    @forelse($recentLists as $list)
                        <a href="{{ route('admin.lists.show', $list) }}"
                            class="block p-2 hover:bg-zinc-50 rounded-lg text-xs font-semibold text-zinc-900 transition-colors">
                            <span>{{ $list->name }}</span>
                            <span class="text-[10px] text-zinc-500 block mt-0.5">{{ $list->total_count }} emails ·
                                {{ $list->status }}</span>
                        </a>
                    @empty
                        <p class="text-zinc-500 text-xs">No lists uploaded yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="app-card app-card-padded">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-zinc-900">Deliverability Tests</h3>
                    <a href="{{ route('admin.deliverability.index') }}"
                        class="text-xs font-bold text-zinc-500 hover:text-zinc-900 underline">New Test</a>
                </div>
                <div class="space-y-2">
                    @forelse($recentDeliverability as $test)
                        <a href="{{ route('admin.deliverability.show', $test) }}"
                            class="block p-2 hover:bg-zinc-50 rounded-lg text-xs font-semibold text-zinc-900 transition-colors">
                            <span>{{ $test->domain }}</span>
                            <span class="text-[10px] text-zinc-500 block mt-0.5">Score:
                                {{ $test->overall_score }}/10</span>
                        </a>
                    @empty
                        <p class="text-zinc-500 text-xs">No tests run yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="app-card app-card-padded">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-zinc-900">Content Analyses</h3>
                    <a href="{{ route('admin.content.index') }}"
                        class="text-xs font-bold text-zinc-500 hover:text-zinc-900 underline">Analyze</a>
                </div>
                <div class="space-y-2">
                    @forelse($recentContent as $content)
                        <a href="{{ route('admin.content.show', $content) }}"
                            class="block p-2 hover:bg-zinc-50 rounded-lg text-xs font-semibold text-zinc-900 transition-colors">
                            <span>{{ $content->title ?? 'Analysis' }}</span>
                            <span class="text-[10px] text-zinc-500 block mt-0.5">Spam risk:
                                {{ $content->overall_score }}/10</span>
                        </a>
                    @empty
                        <p class="text-zinc-500 text-xs">No email content analyzed yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="app-card app-card-padded bg-zinc-50">
            <h3 class="font-extrabold text-zinc-900 mb-2">Quick Start & Maintenance</h3>
            <ol class="text-xs text-zinc-500 space-y-2 list-decimal list-inside leading-relaxed">
                <li>Sync disposable domains helper: <code
                        class="bg-white px-2 py-0.5 border border-zinc-200 rounded text-zinc-900">php artisan
                        email-checker:sync-disposable-domains --sync</code></li>
                <li>Start parallel queue workers for faster lead enrichment:
                    <code class="bg-white px-2 py-0.5 border border-zinc-200 rounded text-zinc-900">php artisan
                        queue:pool</code>
                    <span class="text-zinc-500">(default: 2 workers — set <code class="text-xs">QUEUE_WORKERS=4</code> in
                        <code class="text-xs">.env</code> for 4× parallel)</span>
                </li>
                <li>Upload a CSV lead list and confirm AI mapping definitions</li>
                <li>Verify target domain SPF/DKIM/DMARC prior to deliverability campaigns</li>
                <li>Run spam analyses to check raw outbound template deliverability ratings</li>
            </ol>
        </div>
    </div>
@endsection
