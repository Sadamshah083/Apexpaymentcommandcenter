@extends('layouts.admin')

@section('title', 'Configure pipeline')

@section('content')
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black text-slate-800">Pipeline automation</h1>
            <p class="text-sm text-slate-500 mt-1">Configure and monitor the steps of your lead automation pipeline.</p>
        </div>
        <a href="{{ route('admin.workflows.index') }}" class="text-sm text-slate-500 hover:text-slate-800 font-semibold flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Back to Pipelines
        </a>
    </div>

    <!-- Visual Progress Dashboard (Active/Processing state) -->
    @if($workflow->status !== 'mapping')
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-slate-800">Active Pipeline Tracking</h2>
                    <p class="text-xs text-slate-400 mt-0.5">Automation runs end-to-end; you approve enriched leads before they reach agents.</p>
                    @if($workflow->isProcessing())
                        <p class="text-xs text-amber-700 mt-1">Speed tip: run <code class="bg-amber-50 px-1 rounded">php artisan queue:pool</code> for {{ config('queue.workers', 2) }} parallel workers (set <code class="bg-amber-50 px-1 rounded">QUEUE_WORKERS</code> in <code class="bg-amber-50 px-1 rounded">.env</code>).</p>
                    @endif
                </div>
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    @if($workflow->isProcessing())
                        <form method="POST" action="{{ route('admin.workflows.pause', $workflow->id) }}">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 transition-colors">
                                Stop Processing
                            </button>
                        </form>
                    @endif
                    @if($workflow->isPaused())
                        <form method="POST" action="{{ route('admin.workflows.resume', $workflow->id) }}">
                            @csrf
                            <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition-colors">
                                Resume Processing
                            </button>
                        </form>
                    @endif
                    <span id="workspace-sync-workflow-status" class="px-3 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider
                        {{ $workflow->status === 'completed' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' }}
                        {{ $workflow->status === 'failed' ? 'bg-rose-50 text-rose-700 border border-rose-200' : '' }}
                        {{ $workflow->status === 'extracting' ? 'bg-amber-50 text-amber-700 border border-amber-200 animate-pulse' : '' }}
                        {{ $workflow->status === 'pending' ? 'bg-slate-50 text-slate-700 border border-slate-200' : '' }}
                        {{ $workflow->status === 'paused' ? 'bg-orange-50 text-orange-700 border border-orange-200' : '' }}
                    ">
                        Status: {{ $workflow->status }}
                    </span>
                </div>
            </div>

            <!-- Pipeline Visual Flowchart (Horizontal Trackers) -->
            <div class="flex flex-col md:flex-row items-stretch justify-between gap-4 py-4 border-y border-warmgrey-200 relative">
                <!-- Step 1 -->
                <div class="flex-1 p-4 rounded-xl border border-warmgrey-500 bg-cream-50/50 flex flex-col justify-between">
                    <div>
                        <span class="px-2 py-0.5 bg-cream-200 text-warmgrey-900 border border-warmgrey-500 rounded text-[10px] font-bold">NODE 01</span>
                        <h4 class="font-bold text-warmgrey-900 mt-2 text-sm">File Ingestion</h4>
                        <p class="text-[11px] text-warmgrey-500 mt-1">Headers mapped and parsed successfully.</p>
                    </div>
                    <div class="mt-4 text-xs font-semibold text-emerald-600 flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Parsed ({{ $workflow->total_leads ?: $leads->count() }} rows)
                    </div>
                </div>

                <!-- Connector -->
                <div class="hidden md:flex items-center text-warmgrey-200">&rarr;</div>

                <!-- Step 2 -->
                <div class="flex-1 p-4 rounded-xl border {{ in_array($workflow->status, ['extracting', 'pending']) ? 'border-amber-400 bg-amber-50/10' : '' }} {{ $workflow->status === 'paused' ? 'border-orange-400 bg-orange-50/10' : '' }} flex flex-col justify-between">
                    <div>
                        <span class="px-2 py-0.5 bg-cream-200 text-warmgrey-900 border border-warmgrey-500 rounded text-[10px] font-bold">NODE 02</span>
                        <h4 class="font-bold text-warmgrey-900 mt-2 text-sm">AI Research Agent</h4>
                        <p class="text-[11px] text-warmgrey-500 mt-1">Querying Gemini and scraping web contacts.</p>
                    </div>
                    <div class="mt-4 text-xs font-semibold flex items-center
                        {{ $workflow->status === 'completed' ? 'text-emerald-600' : '' }}
                        {{ $workflow->status === 'extracting' ? 'text-amber-600' : '' }}
                        {{ $workflow->status === 'pending' ? 'text-warmgrey-500' : '' }}
                        {{ $workflow->status === 'paused' ? 'text-orange-600' : '' }}
                    ">
                        @if($workflow->status === 'completed')
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Completed
                        @elseif($workflow->status === 'paused')
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Paused (<span id="workspace-sync-workflow-progress">{{ $workflow->processed_leads + ($workflow->pending_verification_count ?? 0) }}</span> / {{ $workflow->total_leads }} enriched)
                        @elseif($workflow->status === 'extracting')
                            <svg class="animate-spin mr-1.5 h-3.5 w-3.5 text-amber-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                            Active Research (<span id="workspace-sync-workflow-progress">{{ $workflow->processed_leads + ($workflow->pending_verification_count ?? 0) }}</span> / {{ $workflow->total_leads }} enriched)
                        @else
                            Waiting
                        @endif
                    </div>
                </div>

                <!-- Connector -->
                <div class="hidden md:flex items-center text-warmgrey-200">&rarr;</div>

                <!-- Step 3 -->
                <div class="flex-1 p-4 rounded-xl border {{ ($workflow->pending_verification_count ?? 0) > 0 || in_array($workflow->status, ['extracting', 'pending']) ? 'border-indigo-400 bg-indigo-50/10' : 'border-warmgrey-200 bg-cream-50/50' }} flex flex-col justify-between">
                    <div>
                        <span class="px-2 py-0.5 bg-cream-200 text-warmgrey-900 border border-warmgrey-500 rounded text-[10px] font-bold">NODE 03</span>
                        <h4 class="font-bold text-warmgrey-900 mt-2 text-sm">Auto Verification</h4>
                        <p class="text-[11px] text-warmgrey-500 mt-1">SMTP, MX, disposable email, and domain deliverability scans.</p>
                    </div>
                    <div class="mt-4 text-xs font-semibold flex items-center
                        {{ ($workflow->pending_verification_count ?? 0) > 0 ? 'text-indigo-600' : '' }}
                        {{ $workflow->status === 'completed' ? 'text-emerald-600' : '' }}
                    ">
                        @if(($workflow->pending_verification_count ?? 0) > 0)
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Scans complete — <span id="workspace-sync-workflow-pending-review">{{ $workflow->pending_verification_count ?? 0 }}</span> awaiting your review
                        @elseif($workflow->status === 'completed')
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            All scans processed
                        @else
                            Running with enrichment
                        @endif
                    </div>
                </div>

                <!-- Connector -->
                <div class="hidden md:flex items-center text-warmgrey-200">&rarr;</div>

                <!-- Step 4 -->
                <div class="flex-1 p-4 rounded-xl border {{ ($workflow->pending_verification_count ?? 0) > 0 ? 'border-amber-400 bg-amber-50/20' : 'border-warmgrey-200 bg-cream-50/50' }} flex flex-col justify-between">
                    <div>
                        <span class="px-2 py-0.5 bg-cream-200 text-warmgrey-900 border border-warmgrey-500 rounded text-[10px] font-bold">NODE 04</span>
                        <h4 class="font-bold text-warmgrey-900 mt-2 text-sm">Manual Verification</h4>
                        <p class="text-[11px] text-warmgrey-500 mt-1">Review enriched data and approve or reject each lead.</p>
                    </div>
                    <div class="mt-4 text-xs font-semibold flex items-center
                        {{ ($workflow->pending_verification_count ?? 0) > 0 ? 'text-amber-600' : '' }}
                        {{ $workflow->status === 'completed' && ($workflow->pending_verification_count ?? 0) === 0 ? 'text-emerald-600' : '' }}
                    ">
                        @if(($workflow->pending_verification_count ?? 0) > 0)
                            <svg class="animate-pulse w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            Action required (<span id="workspace-sync-workflow-pending-review-2">{{ $workflow->pending_verification_count ?? 0 }}</span> in queue)
                        @elseif($workflow->status === 'completed')
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Queue cleared
                        @else
                            Waiting for enriched leads
                        @endif
                    </div>
                </div>

                <!-- Connector -->
                <div class="hidden md:flex items-center text-warmgrey-200">&rarr;</div>

                <!-- Step 5 -->
                <div class="flex-1 p-4 rounded-xl border {{ ($workflow->assigned_leads_count ?? 0) > 0 ? 'border-emerald-400 bg-emerald-50/10' : 'border-warmgrey-200 bg-cream-50/50' }} flex flex-col justify-between">
                    <div>
                        <span class="px-2 py-0.5 bg-cream-200 text-warmgrey-900 border border-warmgrey-500 rounded text-[10px] font-bold">NODE 05</span>
                        <h4 class="font-bold text-warmgrey-900 mt-2 text-sm">Lead Distribution</h4>
                        <p class="text-[11px] text-warmgrey-500 mt-1">Approved leads are round-robin assigned to your team.</p>
                    </div>
                    <div class="mt-4 text-xs font-semibold flex items-center
                        {{ $workflow->status === 'completed' ? 'text-emerald-600' : '' }}
                        {{ ($workflow->assigned_leads_count ?? 0) > 0 ? 'text-emerald-600' : 'text-warmgrey-500' }}
                    ">
                        @if($workflow->status === 'completed' && ($workflow->assigned_leads_count ?? 0) > 0)
                            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            Distributed (<span id="workspace-sync-workflow-assigned">{{ $workflow->assigned_leads_count ?? 0 }}</span> / {{ $workflow->total_leads }})
                        @elseif(($workflow->assigned_leads_count ?? 0) > 0)
                            <svg class="animate-pulse w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                            Assigning (<span id="workspace-sync-workflow-assigned">{{ $workflow->assigned_leads_count ?? 0 }}</span> released)
                        @else
                            Releases after approval
                        @endif
                    </div>
                </div>
            </div>

            @if(($workflow->pending_verification_count ?? 0) > 0)
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold text-amber-900">{{ $workflow->pending_verification_count }} lead(s) awaiting manual verification</p>
                        <p class="text-xs text-amber-700 mt-0.5">Automation has finished enriching these leads. Approve them to release to your team.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.workflows.approve-leads', $workflow->id) }}" class="flex-shrink-0">
                        @csrf
                        @foreach($workflow->leads()->where('status', 'pending_verification')->pluck('id') as $leadId)
                            <input type="hidden" name="lead_ids[]" value="{{ $leadId }}">
                        @endforeach
                        <button type="submit" class="px-4 py-2 rounded-lg text-xs font-bold bg-emerald-600 text-white hover:bg-emerald-700 transition-colors">
                            Approve All Pending
                        </button>
                    </form>
                </div>
            @endif

            <!-- Progress Bar -->
            @if($workflow->total_leads > 0)
                @php
                    $pct = round((($workflow->processed_leads + $workflow->failed_leads) / $workflow->total_leads) * 100);
                @endphp
                <div class="space-y-2 pt-2">
                    <div class="flex justify-between text-xs text-warmgrey-500 font-semibold">
                        <span>Pipeline completion (approved + rejected)</span>
                        <span id="workspace-sync-workflow-progress-label">{{ $pct }}% ({{ $workflow->processed_leads + $workflow->failed_leads }} / {{ $workflow->total_leads }} leads)</span>
                    </div>
                    <div class="w-full h-3 bg-cream-200 rounded-full overflow-hidden border border-warmgrey-200">
                        <div id="workspace-sync-workflow-progress-bar" class="h-full bg-gradient-to-r from-warmgrey-500 to-warmgrey-900 transition-all duration-500" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endif

            @if($workflow->status === 'failed')
                <div class="p-4 rounded-xl bg-rose-50 border border-rose-100 text-xs text-rose-700 font-semibold">
                    Pipeline Error: {{ $workflow->error_message }}
                </div>
            @endif
        </div>
    @endif

    <!-- Pipeline Automation Configurator (Mapping State) -->
    @if($workflow->status === 'mapping')
        <form method="POST" action="{{ route('admin.workflows.run', $workflow->id) }}" class="space-y-8">
            @csrf

            <!-- Visual Flowchart Cards -->
            <div class="space-y-6">
                
                <!-- NODE 1: Ingestion & Column Mapping -->
                <div class="bg-white rounded-2xl shadow-sm border border-warmgrey-200 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-cream-200 text-warmgrey-900 border border-warmgrey-500 flex items-center justify-center font-bold text-sm">1</div>
                        <div>
                            <h3 class="font-bold text-warmgrey-900 text-base">Node 1: Ingestion & AI Column Mapping</h3>
                            <p class="text-xs text-warmgrey-500 mt-0.5">Columns are auto-mapped by AI when you upload. Review and adjust only if needed.</p>
                        </div>
                    </div>

                    @if(!empty($workflow->column_mapping['business_name']))
                        <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-800 font-semibold">
                            AI mapped <strong>{{ $workflow->column_mapping['business_name'] }}</strong> to Business Name.
                        </div>
                    @elseif(empty($headers))
                        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 font-semibold">
                            Could not read column headers from this file. Re-upload a CSV or Excel file with a header row.
                        </div>
                    @else
                        <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-800 font-semibold">
                            AI could not confidently detect Business Name. Pick the company/business column below before running the pipeline.
                        </div>
                    @endif

                    @if($workflow->sheets)
                        <div class="p-4 bg-cream-100 rounded-xl border border-warmgrey-200 space-y-2">
                            <label for="selected_sheet" class="block text-xs font-bold text-warmgrey-500 uppercase">Select Sheet / Tab</label>
                            <select name="selected_sheet" id="selected_sheet" class="px-3 py-2 bg-white border border-warmgrey-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-warmgrey-500 text-xs">
                                @foreach($workflow->sheets as $sheetName)
                                    <option value="{{ $sheetName }}" {{ $workflow->selected_sheet === $sheetName ? 'selected' : '' }}>{{ $sheetName }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        @php
                            $targetFields = [
                                'business_name' => ['label' => 'Business Name *', 'desc' => 'Name of the business (Required)', 'req' => true],
                                'address' => ['label' => 'Street Address', 'desc' => 'Address line 1', 'req' => false],
                                'city' => ['label' => 'City', 'desc' => 'City name', 'req' => false],
                                'state' => ['label' => 'State', 'desc' => 'State code', 'req' => false],
                                'zip_code' => ['label' => 'ZIP Code', 'desc' => 'ZIP / Postal', 'req' => false],
                                'website' => ['label' => 'Website URL', 'desc' => 'Domain url', 'req' => false],
                                'input_phone' => ['label' => 'Phone', 'desc' => 'Contact phone', 'req' => false],
                                'input_email' => ['label' => 'Email', 'desc' => 'Contact email', 'req' => false],
                            ];
                        @endphp

                        @foreach($targetFields as $key => $info)
                            <div class="p-4 rounded-xl border border-warmgrey-200 bg-cream-50/50 space-y-1">
                                <label class="block text-xs font-bold text-warmgrey-500">{{ $info['label'] }}</label>
                                <select name="mapping[{{ $key }}]" class="w-full mt-1 px-3 py-1.5 bg-white border border-warmgrey-200 rounded-lg focus:outline-none focus:ring-2 focus:ring-warmgrey-500 text-xs">
                                    <option value="">-- Skip --</option>
                                    @foreach($headers as $h)
                                        @if($h !== '')
                                            <option value="{{ $h }}" {{ ($workflow->column_mapping[$key] ?? '') === $h ? 'selected' : '' }}>{{ $h }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- NODE 2: Business Extraction AI Agent -->
                <div class="bg-white rounded-2xl shadow-sm border border-warmgrey-200 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-cream-200 text-warmgrey-900 border border-warmgrey-500 flex items-center justify-center font-bold text-sm">2</div>
                        <div>
                            <h3 class="font-bold text-warmgrey-900 text-base">Node 2: Business Intelligence Research Agent</h3>
                            <p class="text-xs text-warmgrey-500 mt-0.5">Gemini-powered deep web search and information extraction.</p>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label for="custom_prompt" class="block text-xs font-bold text-slate-600 uppercase">Custom Agent Prompt Template</label>
                        <p class="text-[11px] text-slate-400">Modify the instructions sent to Gemini. You can use <code class="bg-slate-100 px-1 rounded">{{ '[INSERT BUSINESS NAME HERE]' }}</code> and <code class="bg-slate-100 px-1 rounded">{{ '[INSERT CITY/STATE HERE]' }}</code> tokens.</p>
                        <textarea name="custom_prompt" id="custom_prompt" rows="10" class="w-full mt-2 p-4 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-xs font-mono">You are a specialized business intelligence data extraction bot. Your sole task is to extract complete, accurate information for the business name provided below.
 
Target Business: [INSERT BUSINESS NAME HERE]
Target Location (City/State): [INSERT CITY/STATE HERE]
 
Search for and extract the following data points. You must present the final output using the exact Markdown schema provided below.
 
### Business Identity & Location
* **Business Name**: [Extract the official trade name or LLC name. If applicable, provide the hyperlink to their main web domain]
* **Physical Address**: [Extract the complete street address, suite number, city, state, and zip code]
* **Primary Service**: [List the core services provided, e.g., Master Barbering, Custom Bridal Tailoring, etc.]
* **Operating Hours**: [List the operational hours for Monday through Sunday]
 
### Owner & Contact Information
* **Direct Owner Name**: [Extract the exact first and last name of the business owner, founder, or managing member. If it is a corporate chain, list the current CEO]
* **Direct Phone Number**: [Extract the primary operational phone number]
* **Direct Email Address**: [Extract the public corporate email address. If they do not use an email and route through a specific portal like Facebook or Booksy, explicitly state that and provide the link]
 
### Payment Processor & Booking Software
* **Payment Processor**: [Identify the backend payment gateway or processing merchant network being used, e.g., Square, Stripe, Clover, Booksy Card Processing, Toast, etc.]
* **System Integration**: [Provide a brief, 2-sentence breakdown explaining how their point-of-sale (POS) hardware, booking software, or online invoicing system integrates with that specific payment processor]
 
STRICT COMPLIANCE RULES:
1. Do not use generic filler information. If a data point cannot be verified through web searches, output "Not Publicly Available".
2. Ensure you look up the specific location provided to avoid confusing identical business names in different states.
3. Keep the output clean, highly dense, and completely factual. Do not include introductory or concluding conversational text.</textarea>
                    </div>
                </div>

                <!-- NODE 3: Verification & Deliverability Rules -->
                <div class="bg-white rounded-2xl shadow-sm border border-warmgrey-200 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-cream-200 text-warmgrey-900 border border-warmgrey-500 flex items-center justify-center font-bold text-sm">3</div>
                        <div>
                            <h3 class="font-bold text-warmgrey-900 text-base">Node 3: Verification & Deliverability Scanning</h3>
                            <p class="text-xs text-warmgrey-500 mt-0.5">Toggle automated verification scripts to validate the extracted data immediately.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                        <label class="p-4 rounded-xl border border-warmgrey-200 bg-cream-50/50 hover:bg-cream-100 cursor-pointer flex items-start gap-3 transition-colors">
                            <input type="checkbox" name="verification_toggles[email]" value="1" checked class="mt-1 rounded border-warmgrey-500 text-warmgrey-900 focus:ring-warmgrey-500">
                            <div>
                                <span class="font-bold text-warmgrey-900 text-xs block">Auto-Verify Email Contacts</span>
                                <span class="text-[10px] text-warmgrey-500 block mt-0.5">SMTP verification, disposable email domains check, and MX verification.</span>
                            </div>
                        </label>

                        <label class="p-4 rounded-xl border border-warmgrey-200 bg-cream-50/50 hover:bg-cream-100 cursor-pointer flex items-start gap-3 transition-colors">
                            <input type="checkbox" name="verification_toggles[domain]" value="1" checked class="mt-1 rounded border-warmgrey-500 text-warmgrey-900 focus:ring-warmgrey-500">
                            <div>
                                <span class="font-bold text-warmgrey-900 text-xs block">Auto-Scan Domain Deliverability</span>
                                <span class="text-[10px] text-warmgrey-500 block mt-0.5">SPF configuration, DKIM selector checks, and DMARC record verification.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- NODE 4: Marketer Distribution Hub -->
                <div class="bg-white rounded-2xl shadow-sm border border-warmgrey-200 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-cream-200 text-warmgrey-900 border border-warmgrey-500 flex items-center justify-center font-bold text-sm">4</div>
                        <div>
                            <h3 class="font-bold text-warmgrey-900 text-base">Node 4: Round-Robin Distribution</h3>
                            <p class="text-xs text-warmgrey-500 mt-0.5">Select team members who receive leads <strong>after you approve</strong> enriched data.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-2">
                        @foreach($team as $member)
                            <label class="p-3 rounded-xl border border-warmgrey-200 bg-cream-50/50 hover:bg-cream-100 cursor-pointer flex items-center gap-3 transition-colors">
                                <input type="checkbox" name="distribution_users[]" value="{{ $member->id }}" checked class="rounded border-warmgrey-500 text-warmgrey-900 focus:ring-warmgrey-500">
                                <div>
                                    <span class="font-bold text-warmgrey-900 text-xs block">{{ $member->name }}</span>
                                    <span class="text-[10px] text-warmgrey-500 block mt-0.5">{{ $member->pivot->role }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>

                <!-- NODE 5: Manual verification gate -->
                <div class="bg-white rounded-2xl shadow-sm border border-amber-200 p-6 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-amber-100 text-amber-900 border border-amber-300 flex items-center justify-center font-bold text-sm">5</div>
                        <div>
                            <h3 class="font-bold text-warmgrey-900 text-base">Node 5: Manual Verification Gate (GoHighLevel-style)</h3>
                            <p class="text-xs text-warmgrey-500 mt-0.5">The pipeline runs automatically through ingestion, AI research, and deliverability scans. Every enriched lead pauses here until you approve or reject it.</p>
                        </div>
                    </div>

                    <label class="p-4 rounded-xl border border-amber-200 bg-amber-50/50 hover:bg-amber-50 cursor-pointer flex items-start gap-3 transition-colors">
                        <input type="checkbox" name="mapping_confirmed" value="1" required class="mt-1 rounded border-amber-400 text-amber-700 focus:ring-amber-500">
                        <div>
                            <span class="font-bold text-warmgrey-900 text-xs block">I have reviewed the column mapping and launch settings</span>
                            <span class="text-[10px] text-warmgrey-500 block mt-0.5">Required before automation starts. You will manually verify each enriched lead before it is assigned to marketers.</span>
                        </div>
                    </label>
                    @error('mapping_confirmed')
                        <p class="text-xs text-rose-600 font-semibold">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Trigger Actions -->
                <div class="flex justify-end gap-3">
                    <a href="{{ route('admin.workflows.index') }}" class="px-5 py-3 btn-secondary text-sm flex items-center justify-center">
                        Cancel Pipeline
                    </a>
                    <button type="submit" class="px-8 py-3 btn-primary text-sm flex items-center justify-center">
                        Launch Automated Pipeline
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </button>
                </div>

            </div>
        </form>
    @endif

    <!-- Pipeline Leads Table -->
    @if($workflow->status !== 'mapping')
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
            <h2 class="text-lg font-bold text-slate-800 mb-4">Pipeline Ingested Leads</h2>

            @if($leads->isEmpty())
                <p class="text-sm text-slate-400 italic">No lead records processed yet. They will appear here once extraction begins.</p>
            @else
                <x-data-table :paginator="$leads" min-width="900px">
                    <table>
                        <thead>
                            <tr>
                                <th>Row</th>
                                <th>Business</th>
                                <th>Address</th>
                                <th>Owner Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Processor</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="workspace-sync-pipeline-leads">
                            @foreach($leads as $lead)
                                <tr>
                                    <td class="text-xs font-mono text-slate-400">#{{ $lead->row_number }}</td>
                                    <td class="font-bold text-warmgrey-900">
                                        <a href="{{ route('portal.leads.show', $lead->id) }}" class="hover:text-warmgrey-500 transition-colors underline">{{ $lead->business_name }}</a>
                                    </td>
                                    <td class="text-xs text-slate-500">
                                        {{ $lead->address ?: 'Not public' }}
                                        <div class="text-[10px] text-slate-400 font-normal">{{ $lead->city }}, {{ $lead->state }}</div>
                                    </td>
                                    <td>{{ $lead->owner_name ?: 'Not public' }}</td>
                                    <td>{{ $lead->direct_email ?: 'Not public' }}</td>
                                    <td>{{ $lead->direct_phone ?: 'Not public' }}</td>
                                    <td>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-slate-100 text-slate-600">
                                            {{ $lead->payment_processor ?: 'Not public' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold
                                            {{ $lead->status === 'completed' ? 'bg-emerald-100 text-emerald-800' : '' }}
                                            {{ $lead->status === 'failed' ? 'bg-rose-100 text-rose-800' : '' }}
                                            {{ $lead->status === 'extracting' ? 'bg-amber-100 text-amber-800 animate-pulse' : '' }}
                                            {{ $lead->status === 'pending_verification' ? 'bg-indigo-100 text-indigo-800' : '' }}
                                            {{ $lead->status === 'pending' ? 'bg-slate-100 text-slate-600' : '' }}
                                        ">
                                            {{ str_replace('_', ' ', $lead->status) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($lead->status === 'pending_verification')
                                            <div class="flex items-center gap-2">
                                                <form method="POST" action="{{ route('admin.leads.approve', $lead->id) }}">
                                                    @csrf
                                                    <button type="submit" class="px-2 py-1 rounded text-[10px] font-bold bg-emerald-600 text-white hover:bg-emerald-700">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.leads.reject', $lead->id) }}" class="flex items-center gap-1">
                                                    @csrf
                                                    <button type="submit" class="px-2 py-1 rounded text-[10px] font-bold bg-rose-100 text-rose-700 hover:bg-rose-200">Reject</button>
                                                </form>
                                            </div>
                                        @elseif($lead->status === 'completed')
                                            <span class="text-[10px] text-emerald-600 font-semibold">Released</span>
                                        @else
                                            <span class="text-[10px] text-slate-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </x-data-table>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<div id="workspace-sync-page" class="hidden" data-workflow-id="{{ $workflow->id }}" aria-hidden="true"></div>
<script>
    document.body.dataset.workspaceWorkflowId = '{{ $workflow->id }}';
</script>
@endpush
