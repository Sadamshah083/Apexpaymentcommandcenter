@extends(request()->is('admin*') ? 'layouts.admin' : 'layouts.portal')

@section('title', 'Pipelines & CRM')

@section('content')
<div class="app-page space-y-8">
    <div class="app-hero">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div>
                <h1 class="app-hero-title">Lead Intelligence Pipeline</h1>
                <p class="app-hero-subtitle">
                    Upload any arbitrary spreadsheet, auto-map columns using AI, extract deep business intelligence (owner, contact info, POS systems), and evenly distribute leads to your team.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if(isset($geminiStatus))
                    <div class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl backdrop-blur-md text-xs">
                        <span class="text-cream-300 block font-semibold uppercase tracking-wider text-[9px]">Gemini API Status</span>
                        <span class="font-bold flex items-center mt-0.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 mr-1.5 animate-pulse"></span>
                            {{ $geminiStatus }}
                        </span>
                    </div>
                @endif
                @if(isset($openRouterBalance) && $openRouterBalance !== null)
                    <div class="px-4 py-2 bg-white/5 border border-white/10 rounded-xl backdrop-blur-md text-xs">
                        <span class="text-cream-300 block font-semibold uppercase tracking-wider text-[9px]">OpenRouter Balance</span>
                        <span class="font-bold flex items-center mt-0.5 text-emerald-300">
                            ${{ number_format($openRouterBalance, 2) }}
                        </span>
                    </div>
                @endif
                @if(request()->is('admin*'))
                <a href="{{ route('admin.workflows.create') }}" class="app-btn app-btn-secondary bg-white text-zinc-900 hover:bg-zinc-100">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    New AI Pipeline
                </a>
                @endif
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="grid grid-cols-1 {{ request()->is('admin*') ? 'lg:grid-cols-3' : '' }} gap-8">
        
        <!-- Left 2 Columns: Active CRM Leads -->
        <div class="{{ request()->is('admin*') ? 'lg:col-span-2' : '' }} space-y-6">
            <div class="app-card app-card-padded">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800">Assigned CRM Leads</h2>
                        <p class="text-sm text-slate-500 mt-0.5">Manage and update leads assigned to you.</p>
                    </div>
                    
                    <!-- Search & Filter Form -->
                    <form method="GET" action="{{ request()->is('admin*') ? route('admin.workflows.index') : route('portal.dashboard') }}" class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search business, owner..." class="w-48 pl-9 pr-4 py-2 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all">
                            <svg class="w-4 h-4 text-slate-400 absolute left-3 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                        <select name="stage" onchange="this.form.submit()" class="py-2 px-3 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">All Stages</option>
                            <option value="lead" {{ request('stage') === 'lead' ? 'selected' : '' }}>Lead</option>
                            <option value="contacted" {{ request('stage') === 'contacted' ? 'selected' : '' }}>Contacted</option>
                            <option value="follow_up" {{ request('stage') === 'follow_up' ? 'selected' : '' }}>Follow-up</option>
                            <option value="interested" {{ request('stage') === 'interested' ? 'selected' : '' }}>Interested</option>
                            <option value="closed_won" {{ request('stage') === 'closed_won' ? 'selected' : '' }}>Deal Closed</option>
                            <option value="closed_lost" {{ request('stage') === 'closed_lost' ? 'selected' : '' }}>Lost</option>
                        </select>
                        @if(request()->anyFilled(['search', 'stage']))
                            <a href="{{ request()->is('admin*') ? route('admin.workflows.index') : route('portal.dashboard') }}" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-xl text-sm" title="Clear Filters">Clear</a>
                        @endif
                    </form>
                </div>

                <!-- Leads Table -->
                @if($leads->isEmpty())
                    <div class="text-center py-12 bg-slate-50 rounded-xl border border-dashed border-slate-200">
                        <svg class="w-12 h-12 text-slate-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                        <p class="text-slate-600 font-semibold">No assigned leads found</p>
                        <p class="text-xs text-slate-400 mt-1">Upload a workflow or check filters</p>
                    </div>
                @else
                    <div class="app-table-wrap">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="border-b border-slate-100 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                                    <th class="py-3 px-4">Business</th>
                                    <th class="py-3 px-4">Owner</th>
                                    <th class="py-3 px-4">Contact</th>
                                    <th class="py-3 px-4">Processor</th>
                                    <th class="py-3 px-4">Stage</th>
                                    <th class="py-3 px-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="workspace-sync-leads-body" class="divide-y divide-slate-50 text-sm text-slate-700">
                                @foreach($leads as $lead)
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-slate-800">{{ $lead->business_name }}</div>
                                            @if($lead->address)
                                                <div class="text-xs text-slate-500 mt-0.5">{{ $lead->address }}</div>
                                            @endif
                                            <div class="text-[10px] text-slate-400 font-normal mt-0.5">
                                                {{ $lead->city }}, {{ $lead->state }}
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 font-medium text-slate-600">
                                            {{ $lead->owner_name ?: 'Not Found' }}
                                        </td>
                                        <td class="py-3.5 px-4">
                                            @if($lead->direct_email && $lead->direct_email !== 'Not Publicly Available')
                                                <div class="text-slate-700">{{ $lead->direct_email }}</div>
                                            @endif
                                            @if($lead->direct_phone && $lead->direct_phone !== 'Not Publicly Available')
                                                <div class="text-xs text-slate-400 mt-0.5">{{ $lead->direct_phone }}</div>
                                            @endif
                                            @if(!$lead->direct_email && !$lead->direct_phone)
                                                <span class="text-xs text-slate-400 font-italic">None available</span>
                                            @endif
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700">
                                                {{ $lead->payment_processor ?: 'Unknown' }}
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold
                                                {{ $lead->stage === 'closed_won' ? 'bg-emerald-50 text-emerald-700' : '' }}
                                                {{ $lead->stage === 'closed_lost' ? 'bg-rose-50 text-rose-700' : '' }}
                                                {{ $lead->stage === 'follow_up' ? 'bg-amber-50 text-amber-700' : '' }}
                                                {{ $lead->stage === 'interested' ? 'bg-indigo-50 text-indigo-700' : '' }}
                                                {{ $lead->stage === 'lead' ? 'bg-slate-100 text-slate-700' : '' }}
                                                {{ $lead->stage === 'contacted' ? 'bg-sky-50 text-sky-700' : '' }}
                                            ">
                                                {{ ucfirst(str_replace('_', ' ', $lead->stage)) }}
                                            </span>
                                        </td>
                                        <td class="py-3.5 px-4 text-right">
                                            <a href="{{ route('portal.leads.show', $lead->id) }}" class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">
                        <x-pagination :paginator="$leads" />
                    </div>
                @endif
            </div>
        </div>

        @if(request()->is('admin*'))
        <!-- Right 1 Column: Pipelines log & Team members -->
        <div class="space-y-6">
            <!-- Pipelines -->
            <div class="app-card app-card-padded">
                <h2 class="text-xl font-bold text-slate-800 mb-4">Pipeline Ingestions</h2>
                
                @if($workflows->isEmpty())
                    <p class="text-sm text-slate-400 italic">No workflow history.</p>
                @else
                    <div id="workspace-sync-workflows" class="space-y-4">
                        @foreach($workflows as $wf)
                            <div class="p-4 rounded-xl bg-slate-50/50 border border-slate-100 hover:border-indigo-100 transition-colors relative group">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <h3 class="font-bold text-slate-800 text-sm truncate max-w-[180px]">{{ $wf->name }}</h3>
                                        <p class="text-[11px] text-slate-400 truncate mt-0.5">{{ $wf->original_filename }}</p>
                                    </div>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider
                                        {{ $wf->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : '' }}
                                        {{ $wf->status === 'failed' ? 'bg-rose-100 text-rose-800' : '' }}
                                        {{ $wf->status === 'extracting' ? 'bg-amber-100 text-amber-800 animate-pulse' : '' }}
                                        {{ $wf->status === 'mapping' ? 'bg-blue-100 text-blue-800' : '' }}
                                        {{ $wf->status === 'pending' ? 'bg-slate-200 text-slate-700' : '' }}
                                        {{ $wf->status === 'paused' ? 'bg-orange-100 text-orange-800' : '' }}
                                    ">
                                        {{ $wf->status }}
                                    </span>
                                </div>
                                <div class="mt-3 flex items-center justify-between text-xs text-slate-500">
                                    <span>
                                        Processed: <strong class="text-slate-800">{{ $wf->processed_leads }}</strong> / {{ $wf->total_leads }}
                                    </span>
                                    <span>{{ $wf->created_at->diffForHumans() }}</span>
                                </div>
                                <div class="mt-3 flex items-center justify-end gap-2 flex-wrap">
                                    <a href="{{ route('admin.workflows.show', $wf->id) }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold">View mapping</a>
                                    @if(in_array($wf->status, ['pending', 'extracting']))
                                        <form method="POST" action="{{ route('admin.workflows.pause', $wf->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs text-amber-700 hover:text-amber-900 font-semibold">Stop</button>
                                        </form>
                                    @endif
                                    @if($wf->status === 'paused')
                                        <form method="POST" action="{{ route('admin.workflows.resume', $wf->id) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs text-emerald-600 hover:text-emerald-800 font-semibold">Resume</button>
                                        </form>
                                    @endif
                                    <form method="POST" action="{{ route('admin.workflows.destroy', $wf->id) }}" class="inline" onsubmit="return confirm('Delete this pipeline and all {{ $wf->total_leads }} lead records from the database?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs text-rose-600 hover:text-rose-800 font-semibold ml-2">Delete</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <x-pagination :paginator="$workflows" class="mt-4 pt-4 border-t border-slate-100" />
                @endif
            </div>

            <!-- Workspace Team Members -->
            <div class="app-card app-card-padded">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-slate-800">Workspace Team</h2>
                    <a href="{{ route('admin.workspaces.index') }}" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold">Invite</a>
                </div>
                
                <div id="workspace-sync-team" class="divide-y divide-slate-50">
                    @foreach($team as $member)
                        <div class="py-3 flex items-center justify-between">
                            <div>
                                <div class="font-bold text-slate-800 text-sm">{{ $member->name }}</div>
                                <div class="text-xs text-slate-400 mt-0.5">{{ $member->email }}</div>
                            </div>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 text-slate-600">
                                {{ $member->pivot->role }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

    </div>
</div>
@endsection
