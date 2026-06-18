@extends('layouts.portal')

@section('title', 'CRM Lead - ' . $lead->business_name)

@section('content')
<div class="app-page space-y-6">
    <div class="app-page-header flex items-center justify-between">
        <div>
            <h1 class="app-page-title">{{ $lead->business_name }}</h1>
            <p class="app-page-subtitle">Lead ID: #{{ $lead->id }} &bull; Ingested {{ $lead->created_at->format('M d, Y') }}</p>
        </div>
        <a href="{{ route('portal.dashboard') }}" class="app-btn app-btn-secondary text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            Back to Dashboard
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-6">
            <div class="app-card app-card-padded">
                <form method="POST" action="{{ route('portal.leads.update', $lead->id) }}" class="space-y-8">
                    @csrf

                    <!-- B2B Marketing Stage & Allocation -->
                    <div class="p-6 bg-slate-50 rounded-2xl border border-slate-100 grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="space-y-1">
                            <label for="stage" class="block text-xs font-bold text-slate-500 uppercase tracking-wider">CRM Pipeline Stage</label>
                            <select name="stage" id="stage" class="w-full mt-1 px-3 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                                <option value="lead" {{ $lead->stage === 'lead' ? 'selected' : '' }}>Lead Ingested</option>
                                <option value="contacted" {{ $lead->stage === 'contacted' ? 'selected' : '' }}>Contacted</option>
                                <option value="follow_up" {{ $lead->stage === 'follow_up' ? 'selected' : '' }}>Follow-up Scheduled</option>
                                <option value="interested" {{ $lead->stage === 'interested' ? 'selected' : '' }}>Interested</option>
                                <option value="closed_won" {{ $lead->stage === 'closed_won' ? 'selected' : '' }}>Deal Closed (Won)</option>
                                <option value="closed_lost" {{ $lead->stage === 'closed_lost' ? 'selected' : '' }}>Deal Lost</option>
                            </select>
                        </div>
                        
                        <div class="space-y-1">
                            <label for="assigned_user_id" class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Assigned Marketer</label>
                            <select name="assigned_user_id" id="assigned_user_id" class="w-full mt-1 px-3 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                                <option value="">-- Unassigned --</option>
                                @foreach($team as $member)
                                    <option value="{{ $member->id }}" {{ $lead->assigned_user_id === $member->id ? 'selected' : '' }}>
                                        {{ $member->name }} ({{ $member->pivot->role }})
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1">
                            <label for="sale_value" class="block text-xs font-bold text-slate-500 uppercase tracking-wider">Estimated Value ($)</label>
                            <input type="number" step="0.01" name="sale_value" id="sale_value" value="{{ $lead->sale_value }}" class="w-full mt-1 px-3 py-2 bg-white border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                        </div>
                    </div>

                    <!-- Business Identity Info -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">Business Identity & Location</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label for="business_name" class="text-xs font-semibold text-slate-600">Business Name</label>
                                <input type="text" name="business_name" id="business_name" value="{{ $lead->business_name }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="website" class="text-xs font-semibold text-slate-600">Website</label>
                                <input type="text" name="website" id="website" value="{{ $lead->website }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1 md:col-span-2">
                                <label for="address" class="text-xs font-semibold text-slate-600">Physical Address</label>
                                <input type="text" name="address" id="address" value="{{ $lead->address }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="city" class="text-xs font-semibold text-slate-600">City</label>
                                <input type="text" name="city" id="city" value="{{ $lead->city }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="state" class="text-xs font-semibold text-slate-600">State / Province</label>
                                <input type="text" name="state" id="state" value="{{ $lead->state }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="zip_code" class="text-xs font-semibold text-slate-600">ZIP / Postal Code</label>
                                <input type="text" name="zip_code" id="zip_code" value="{{ $lead->zip_code }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="country" class="text-xs font-semibold text-slate-600">Country</label>
                                <input type="text" name="country" id="country" value="{{ $lead->country }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Enriched Owner & Contact Information -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">AI-Enriched Lead Contacts</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="space-y-1">
                                <label for="owner_name" class="text-xs font-semibold text-slate-600">Direct Owner Name</label>
                                <input type="text" name="owner_name" id="owner_name" value="{{ $lead->owner_name }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="direct_email" class="text-xs font-semibold text-slate-600">Direct Email</label>
                                <input type="text" name="direct_email" id="direct_email" value="{{ $lead->direct_email }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="direct_phone" class="text-xs font-semibold text-slate-600">Direct Phone</label>
                                <input type="text" name="direct_phone" id="direct_phone" value="{{ $lead->direct_phone }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Enriched Services & Operating Hours -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">AI-Enriched Logistics</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label for="primary_service" class="text-xs font-semibold text-slate-600">Primary Service</label>
                                <input type="text" name="primary_service" id="primary_service" value="{{ $lead->primary_service }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="payment_processor" class="text-xs font-semibold text-slate-600">Payment Processor</label>
                                <input type="text" name="payment_processor" id="payment_processor" value="{{ $lead->payment_processor }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1 md:col-span-2">
                                <label for="operating_hours" class="text-xs font-semibold text-slate-600">Operating Hours</label>
                                <textarea name="operating_hours" id="operating_hours" rows="3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">{{ $lead->operating_hours }}</textarea>
                            </div>
                            <div class="space-y-1 md:col-span-2">
                                <label for="system_integration" class="text-xs font-semibold text-slate-600">System Integration Details</label>
                                <textarea name="system_integration" id="system_integration" rows="3" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">{{ $lead->system_integration }}</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- CRM Schedulers & Notes -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-slate-400 uppercase tracking-wider">CRM Records & Notes</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label for="followup_at" class="text-xs font-semibold text-slate-600">Follow-up Date/Time</label>
                                <input type="datetime-local" name="followup_at" id="followup_at" value="{{ $lead->followup_at ? $lead->followup_at->format('Y-m-d\TH:i') : '' }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1">
                                <label for="schedule_at" class="text-xs font-semibold text-slate-600">Event/Meeting Scheduled Date</label>
                                <input type="datetime-local" name="schedule_at" id="schedule_at" value="{{ $lead->schedule_at ? $lead->schedule_at->format('Y-m-d\TH:i') : '' }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">
                            </div>
                            <div class="space-y-1 md:col-span-2">
                                <label for="notes" class="text-xs font-semibold text-slate-600">Interaction Log / Marketing Notes</label>
                                <textarea name="notes" id="notes" rows="4" placeholder="Log details of call, email threads, followups..." class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 text-sm">{{ $lead->notes }}</textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Save changes -->
                    <div class="flex items-center justify-between pt-6 border-t border-slate-100">
                        <button type="submit" class="app-btn app-btn-primary px-6 py-3">
                            Save CRM Updates
                        </button>
                    </div>
                </form>

                <form method="POST" action="{{ route('portal.leads.destroy', $lead->id) }}" onsubmit="return confirm('Remove this lead record permanently?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-semibold underline">
                        Delete Lead Record
                    </button>
                </form>
            </div>
        </div>

        <div class="lg:col-span-1 space-y-6">
        <div class="app-card app-card-padded space-y-4">
            <h2 class="text-lg font-bold text-zinc-900 flex items-center">
                <svg class="w-5 h-5 mr-2 text-zinc-900" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                Unified Validation Agent
            </h2>
            <p class="text-xs text-zinc-500">Run inline checks from the Email Verification and Deliverability suite.</p>

            <div class="flex border-b border-zinc-200 text-xs gap-2">
                <button type="button" onclick="switchToolkitTab('email-verify')" id="tab-email-verify" class="pb-2 font-bold border-b-2 border-zinc-900 text-zinc-900 transition-all">Email Check</button>
                <button type="button" onclick="switchToolkitTab('spam-analyzer')" id="tab-spam-analyzer" class="pb-2 font-semibold border-b-2 border-transparent text-zinc-500 hover:text-zinc-900 transition-all">Spam Check</button>
                <button type="button" onclick="switchToolkitTab('domain-auth')" id="tab-domain-auth" class="pb-2 font-semibold border-b-2 border-transparent text-zinc-500 hover:text-zinc-900 transition-all">Domain Auth</button>
            </div>
 
            <!-- Tab 1: Email Verifier -->
            <div id="content-email-verify" class="space-y-4 py-2">
                <div class="text-[11px] text-warmgrey-500">
                    Validate this lead's email against syntax, mx records, disposable databases, and SMTP connectivity.
                </div>
                <button type="button" onclick="runEmailVerifier()" id="btn-run-verify" class="w-full py-2 app-btn app-btn-primary text-xs">
                    Run Verification Pipeline
                </button>
                <div id="email-verify-results" class="hidden space-y-2 text-xs">
                    <div class="border-t border-slate-100 pt-2 flex justify-between">
                        <span class="text-slate-400">Syntax Check</span>
                        <span id="res-syntax" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">MX Records</span>
                        <span id="res-mx" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Disposable Domain</span>
                        <span id="res-disposable" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">SMTP Verification</span>
                        <span id="res-smtp" class="font-bold text-slate-800">-</span>
                    </div>
                </div>
            </div>

            <!-- Tab 2: Outbound Email Analyzer -->
            <div id="content-spam-analyzer" class="hidden space-y-3 py-2">
                <div class="text-[11px] text-warmgrey-500">
                    Draft your email to this client and verify the spam triggers and score.
                </div>
                <input type="text" id="spam-subject" placeholder="Email Subject Line" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-warmgrey-500">
                <textarea id="spam-body" rows="4" placeholder="Outreach Body..." class="w-full px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:bg-white focus:outline-none focus:ring-1 focus:ring-warmgrey-500"></textarea>
                <button type="button" onclick="runSpamAnalyzer()" id="btn-run-spam" class="w-full py-2 app-btn app-btn-primary text-xs">
                    Analyze Spam Score
                </button>
                <div id="spam-analyzer-results" class="hidden p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-2 text-xs">
                    <div class="flex justify-between items-center">
                        <span class="font-semibold text-slate-500">Spam Score:</span>
                        <span id="res-spam-score" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="font-semibold text-slate-500">Overall Rating:</span>
                        <span id="res-spam-overall" class="font-bold text-warmgrey-900">-</span>
                    </div>
                    <div class="border-t border-slate-200 pt-2">
                        <span class="font-semibold text-slate-500 block mb-1">Suggestions:</span>
                        <ul id="res-spam-suggestions" class="list-disc list-inside space-y-1 text-[11px] text-slate-400"></ul>
                    </div>
                </div>
            </div>

            <!-- Tab 3: Domain Auth -->
            <div id="content-domain-auth" class="hidden space-y-4 py-2">
                <div class="text-[11px] text-warmgrey-500">
                    Verify deliverability configurations (SPF, DKIM, DMARC, MX) on the lead's domain.
                </div>
                <button type="button" onclick="runDomainAuth()" id="btn-run-domain" class="w-full py-2 app-btn app-btn-primary text-xs">
                    Verify Domain Auth
                </button>
                <div id="domain-auth-results" class="hidden space-y-2 text-xs">
                    <div class="border-t border-slate-100 pt-2 flex justify-between">
                        <span class="text-slate-400">SPF Configuration</span>
                        <span id="res-spf" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">DMARC Registry</span>
                        <span id="res-dmarc" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">MX Setup</span>
                        <span id="res-auth-mx" class="font-bold text-slate-800">-</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-400">Overall Deliverability</span>
                        <span id="res-auth-score" class="font-bold text-warmgrey-900">-</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="app-card app-card-padded space-y-4">
            <h2 class="text-lg font-bold text-zinc-900">AI Intelligence Report</h2>
            <p class="text-xs text-slate-400">Gemini Grounded business intelligence extraction.</p>
            
            <div class="prose prose-slate max-w-none p-4 bg-slate-50 rounded-xl border border-slate-100 max-h-[600px] overflow-y-auto text-xs font-mono whitespace-pre-wrap">
                @if($lead->markdown_report)
                    {{ $lead->markdown_report }}
                @else
                    <span class="italic text-slate-400">No raw intelligence report generated. Lead status is {{ $lead->status }}.</span>
                @endif
            </div>
            
            @if($lead->model_used)
                <div class="text-[10px] text-slate-400 space-y-1">
                    <div>Model: <strong class="text-slate-600">{{ $lead->model_used }}</strong></div>
                    <div>Tokens: <strong class="text-slate-600">{{ $lead->tokens_used }}</strong></div>
                    <div>Time: <strong class="text-slate-600">{{ $lead->researched_at ? $lead->researched_at->diffForHumans() : 'N/A' }}</strong></div>
                </div>
            @endif
        </div>

        <div class="app-card app-card-padded space-y-4">
            <h2 class="text-lg font-bold text-zinc-900">Original Row Input</h2>
            <div class="space-y-2">
                @if($lead->raw_row)
                    @foreach($lead->raw_row as $key => $val)
                        <div class="text-xs border-b border-slate-50 pb-1.5 flex justify-between gap-4">
                            <span class="text-slate-400 font-semibold truncate">{{ $key }}</span>
                            <span class="text-slate-700 font-medium truncate max-w-[200px]" title="{{ $val }}">{{ $val ?: 'Empty' }}</span>
                        </div>
                    @endforeach
                @else
                    <span class="italic text-xs text-slate-400">No raw row details captured.</span>
                @endif
            </div>
        </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    function switchToolkitTab(tabId) {
        const tabs = ['email-verify', 'spam-analyzer', 'domain-auth'];
        tabs.forEach(t => {
            document.getElementById(`content-${t}`).classList.add('hidden');
            const tabBtn = document.getElementById(`tab-${t}`);
            tabBtn.classList.remove('border-zinc-900', 'text-zinc-900', 'font-bold');
            tabBtn.classList.add('border-transparent', 'text-zinc-400', 'font-semibold');
        });

        document.getElementById(`content-${tabId}`).classList.remove('hidden');
        const activeBtn = document.getElementById(`tab-${tabId}`);
        activeBtn.classList.add('border-zinc-900', 'text-zinc-900', 'font-bold');
        activeBtn.classList.remove('border-transparent', 'text-zinc-400', 'font-semibold');
    }

    function runEmailVerifier() {
        const btn = document.getElementById('btn-run-verify');
        const results = document.getElementById('email-verify-results');
        
        btn.disabled = true;
        btn.innerText = 'Verifying Email...';
        results.classList.remove('hidden');

        document.getElementById('res-syntax').innerText = 'Running...';
        document.getElementById('res-mx').innerText = 'Running...';
        document.getElementById('res-disposable').innerText = 'Running...';
        document.getElementById('res-smtp').innerText = 'Running...';

        fetch('{{ route("portal.leads.verify-email", $lead->id) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = 'Run Verification Pipeline';
            
            if (data.error) {
                window.showToast(data.error, 'error');
                return;
            }

            document.getElementById('res-syntax').innerText = data.syntax.status.toUpperCase();
            document.getElementById('res-mx').innerText = data.mx.status.toUpperCase();
            document.getElementById('res-disposable').innerText = data.disposable.status.toUpperCase();
            document.getElementById('res-smtp').innerText = data.smtp.status.toUpperCase();
            window.showToast('Email verification complete.', 'success');
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = 'Run Verification Pipeline';
            window.showToast('Failed to execute email check.', 'error');
        });
    }

    function runSpamAnalyzer() {
        const btn = document.getElementById('btn-run-spam');
        const results = document.getElementById('spam-analyzer-results');
        const subject = document.getElementById('spam-subject').value;
        const body = document.getElementById('spam-body').value;

        if (!subject || !body) {
            window.showToast('Please enter a subject and email body.', 'warning');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Analyzing Outbound Content...';

        fetch('{{ route("portal.leads.analyze-email", $lead->id) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ subject, body })
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = 'Analyze Spam Score';

            if (data.error) {
                window.showToast(data.error, 'error');
                return;
            }

            results.classList.remove('hidden');
            document.getElementById('res-spam-score').innerText = `${data.spam_score}/10`;
            document.getElementById('res-spam-overall').innerText = `${data.overall_score}/10`;
            
            const list = document.getElementById('res-spam-suggestions');
            list.innerHTML = '';
            data.suggestions.forEach(s => {
                const li = document.createElement('li');
                li.innerText = s;
                list.appendChild(li);
            });
            window.showToast('Spam analysis complete.', 'success');
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = 'Analyze Spam Score';
            window.showToast('Failed to execute spam analysis.', 'error');
        });
    }

    function runDomainAuth() {
        const btn = document.getElementById('btn-run-domain');
        const results = document.getElementById('domain-auth-results');

        btn.disabled = true;
        btn.innerText = 'Checking Domain Configuration...';
        results.classList.remove('hidden');

        document.getElementById('res-spf').innerText = 'Running...';
        document.getElementById('res-dmarc').innerText = 'Running...';
        document.getElementById('res-auth-mx').innerText = 'Running...';
        document.getElementById('res-auth-score').innerText = 'Running...';

        fetch('{{ route("portal.leads.check-domain", $lead->id) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(res => res.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = 'Verify Domain Auth';

            if (data.error) {
                window.showToast(data.error, 'error');
                return;
            }

            document.getElementById('res-spf').innerText = data.spf.passed ? 'PASS' : 'FAIL';
            document.getElementById('res-dmarc').innerText = data.dmarc.passed ? 'PASS' : 'FAIL';
            document.getElementById('res-auth-mx').innerText = data.mx.passed ? 'PASS' : 'FAIL';
            document.getElementById('res-auth-score').innerText = `${data.overall_score}/10`;
            window.showToast('Domain authentication check complete.', 'success');
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerText = 'Verify Domain Auth';
            window.showToast('Failed to execute domain check.', 'error');
        });
    }
</script>
@endpush
@endsection

