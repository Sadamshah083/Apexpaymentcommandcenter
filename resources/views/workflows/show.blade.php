@extends('layouts.admin')

@section('title', $workflow->name)

@section('content')
@php
    use App\Support\LeadContactDisplay;

    $attempted = ($workflow->enriched_leads ?? 0) + ($workflow->failed_leads ?? 0);
    $pct = $workflow->total_leads > 0
        ? (int) round(($attempted / $workflow->total_leads) * 100)
        : 0;
    $importedCount = $workflow->imported_leads_count ?? 0;
    $enrichedCount = $workflow->enriched_leads_count ?? 0;
    $readyToDistribute = $workflow->ready_to_distribute_count ?? 0;
@endphp

<div class="app-page space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
            <x-back-link :href="route('admin.workflows.index')" label="All imports" />
            <h1 class="app-page-title mt-2">{{ $workflow->name }}</h1>
            <p class="app-page-subtitle">{{ number_format($workflow->total_leads) }} leads &middot; {{ $workflow->original_filename }}</p>
        </div>
        @if($workflow->status !== 'mapping')
            <div class="flex items-center gap-2 flex-shrink-0">
                @if($workflow->isProcessing())
                    <form method="POST" action="{{ route('admin.workflows.pause', $workflow->id) }}">
                        @csrf
                        <button type="submit" class="app-btn app-btn-secondary app-btn-sm">Pause</button>
                    </form>
                @endif
                @if($workflow->isPaused())
                    <form method="POST" action="{{ route('admin.workflows.resume', $workflow->id) }}">
                        @csrf
                        <button type="submit" class="app-btn app-btn-primary app-btn-sm">Resume</button>
                    </form>
                @endif
                <x-workflow-status-pill :status="$workflow->status" id="workspace-sync-workflow-status" />
            </div>
        @endif
    </div>

    @if($workflow->status === 'mapping')
        <form method="POST" action="{{ route('admin.workflows.run', $workflow->id) }}" class="space-y-6">
            @csrf

            <div class="app-card app-card-padded space-y-5">
                <div class="app-step-header">
                    <span class="app-step-number">1</span>
                    <div>
                        <h2 class="app-section-title">Match your columns</h2>
                        <p class="app-section-desc">We auto-detected your spreadsheet. Confirm business name, then start.</p>
                    </div>
                </div>

                @if(!empty($workflow->column_mapping['business_name']))
                    <div class="app-alert app-alert-success">
                        <p class="app-alert-title">Mapped <strong>{{ $workflow->column_mapping['business_name'] }}</strong> to Business Name</p>
                    </div>
                @elseif(!empty($headers))
                    <div class="app-alert app-alert-warning">
                        <p class="app-alert-title">Select which column contains the business name</p>
                    </div>
                @endif

                @if($workflow->sheets)
                    <div class="app-field">
                        <label for="selected_sheet" class="app-label">Sheet</label>
                        <select name="selected_sheet" id="selected_sheet" class="app-input max-w-xs">
                            @foreach($workflow->sheets as $sheetName)
                                <option value="{{ $sheetName }}" {{ $workflow->selected_sheet === $sheetName ? 'selected' : '' }}>{{ $sheetName }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="app-field">
                        <label class="app-label">Business name <span class="text-rose-600">*</span></label>
                        <select name="mapping[business_name]" required class="app-input">
                            <option value="">Select columnΓÇª</option>
                            @foreach($headers as $h)
                                @if($h !== '')
                                    <option value="{{ $h }}" {{ ($workflow->column_mapping['business_name'] ?? '') === $h ? 'selected' : '' }}>{{ $h }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    @foreach(['owner_name' => 'Owner / Contact', 'city' => 'City', 'state' => 'State', 'input_phone' => 'Phone', 'input_email' => 'Email', 'website' => 'Website'] as $key => $label)
                        <div class="app-field">
                            <label class="app-label">{{ $label }}</label>
                            <select name="mapping[{{ $key }}]" class="app-input">
                                <option value="">Skip</option>
                                @foreach($headers as $h)
                                    @if($h !== '')
                                        <option value="{{ $h }}" {{ ($workflow->column_mapping[$key] ?? '') === $h ? 'selected' : '' }}>{{ $h }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <details class="app-details">
                    <summary>More columns (address, ZIPΓÇª)</summary>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-zinc-100">
                        @foreach(['address' => 'Street address', 'zip_code' => 'ZIP'] as $key => $label)
                            <div class="app-field">
                                <label class="app-label">{{ $label }}</label>
                                <select name="mapping[{{ $key }}]" class="app-input">
                                    <option value="">Skip</option>
                                    @foreach($headers as $h)
                                        @if($h !== '')
                                            <option value="{{ $h }}" {{ ($workflow->column_mapping[$key] ?? '') === $h ? 'selected' : '' }}>{{ $h }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                    </div>
                </details>
            </div>

            <div class="app-card app-card-padded space-y-4">
                <div class="app-step-header">
                    <span class="app-step-number">2</span>
                    <div>
                        <h2 class="app-section-title">Segment your list</h2>
                        <p class="app-section-desc">HubSpot-style lists and tags ΓÇö every imported lead is added to a static list and tagged for filtering.</p>
                    </div>
                </div>

                <div class="app-field">
                    <label class="app-label">Static list</label>
                    <p class="text-sm text-zinc-700 font-semibold">{{ $workflow->name }}</p>
                    <p class="app-field-hint">A new list is created with this import name.</p>
                </div>

                @if(($leadTags ?? collect())->isNotEmpty())
                    <div class="app-field">
                        <span class="app-label">Tags</span>
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach($leadTags as $tag)
                                <label class="app-member-chip">
                                    <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}">
                                    <span class="app-member-chip-name" style="border-left: 3px solid {{ $tag->color }}">{{ $tag->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="app-field">
                    <label for="tag_names" class="app-label">New tags</label>
                    <input type="text" name="tag_names" id="tag_names" class="app-input" placeholder="e.g. cold-outreach, texas, june-2026">
                    <p class="app-field-hint">Comma-separated. Tags trace leads across imports ΓÇö use them for batch enrich and assign in Lead Tags.</p>
                </div>

                <input type="hidden" name="verification_toggles[email]" value="0">
                <input type="hidden" name="verification_toggles[domain]" value="0">
            </div>

            <div class="app-card app-card-padded space-y-4 border-zinc-300">
                <div class="app-step-header">
                    <span class="app-step-number">3</span>
                    <div>
                        <h2 class="app-section-title">Import options</h2>
                        <p class="app-section-desc">Leads are imported unassigned. Enrichment and setter distribution are separate steps you can run later.</p>
                    </div>
                </div>

                <label class="app-checkbox-row">
                    <input type="checkbox" name="run_enrichment_on_import" value="1" @checked($enrichmentConfigured ?? false) @disabled(!($enrichmentConfigured ?? false))>
                    <span class="app-checkbox-row-text">
                        <strong>Run AI enrichment now</strong>
                        @if(!($enrichmentConfigured ?? false))
                            <span class="block text-xs text-zinc-500 font-normal mt-0.5">Requires GEMINI_API_KEY or OPENROUTER_API_KEY on the server.</span>
                        @else
                            <span class="block text-xs text-zinc-500 font-normal mt-0.5">Uncheck to import only ΓÇö enrich from this page when ready.</span>
                        @endif
                    </span>
                </label>

                <label class="app-checkbox-row">
                    <input type="checkbox" name="mapping_confirmed" value="1" required>
                    <span class="app-checkbox-row-text">I've reviewed the column mapping. Duplicate US phone numbers in this workspace will be skipped.</span>
                </label>
                @error('mapping_confirmed')
                    <p class="text-xs text-rose-600 font-semibold">{{ $message }}</p>
                @enderror
                @error('enrichment')
                    <p class="text-xs text-rose-600 font-semibold">{{ $message }}</p>
                @enderror

                <details class="app-details">
                    <summary>Advanced: custom AI instructions</summary>
                    <textarea name="custom_prompt" rows="8" class="app-input mt-3 font-mono text-xs">You are a specialized business intelligence data extraction bot. Your sole task is to extract complete, accurate information for the business name provided below.

Target Business: [INSERT BUSINESS NAME HERE]
Target Location (City/State): [INSERT CITY/STATE HERE]

Extract business identity, owner contact, payment processor, and booking/POS software. Output factual data only ΓÇö use "Not Publicly Available" when unknown.</textarea>
                </details>

                <div class="flex justify-end gap-3 pt-2">
                    <a href="{{ route('admin.workflows.index') }}" class="app-btn app-btn-secondary">Cancel</a>
                    <button type="submit" class="app-btn app-btn-primary">Start import</button>
                </div>
            </div>
        </form>
    @else
        @if(isset($enrichmentStatus))
            @include('workflows.partials.enrichment-status', ['status' => $enrichmentStatus])
        @endif

        <div class="app-card app-card-padded space-y-5">
            @include('workflows.partials.pipeline-progress')

            @if($workflow->total_leads > 0)
                <div class="space-y-2">
                    <div class="flex justify-between text-xs font-semibold text-zinc-500">
                        <span>Overall progress</span>
                        <span id="workspace-sync-workflow-progress-label">{{ $pct }}% &middot; {{ $attempted }} / {{ $workflow->total_leads }}</span>
                    </div>
                    <div class="app-progress-track">
                        <div id="workspace-sync-workflow-progress-bar" class="app-progress-fill" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endif

            @if(($workflow->discarded_duplicates ?? 0) > 0)
                <div class="app-alert app-alert-warning">
                    <p class="app-alert-title">{{ number_format($workflow->discarded_duplicates) }} rows were skipped on a previous import (duplicate phones)</p>
                    <p class="app-alert-desc">Delete this import and re-upload the CSV to import every row. Phone duplicate skipping is now disabled for new imports.</p>
                </div>
            @endif

            @if(!empty($workflow->import_tag_ids) || ($leadList ?? null))
                <div class="app-alert app-alert-info flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        @if($leadList ?? null)
                            <p class="app-alert-title">List: {{ $leadList->name }}</p>
                        @endif
                        @if(!empty($workflow->import_tag_ids))
                            <p class="app-alert-desc">Batch enrich or assign all leads with these tags ΓÇö including from other files.</p>
                        @elseif($leadList ?? null)
                            <p class="app-alert-desc">All leads in this import belong to this static list.</p>
                        @endif
                    </div>
                    @if(!empty($workflow->import_tag_ids))
                        <a href="{{ route('admin.lead-tags.show', ['tag_ids' => $workflow->import_tag_ids]) }}" class="app-btn app-btn-secondary app-btn-sm whitespace-nowrap">Open in Lead Tags</a>
                    @endif
                </div>
            @endif

            @if($readyToDistribute > 0 && ! $workflow->isProcessing())
                <div class="app-alert app-alert-info">
                    <p class="app-alert-title">{{ number_format($readyToDistribute) }} of {{ number_format($workflow->total_leads) }} leads still need assignment</p>
                    <p class="app-alert-desc">Assign all leads to setters below. They will appear in Active leads only after assignment is complete.</p>
                </div>
            @endif

            @if($importedCount > 0 && ! $workflow->isProcessing() && ($enrichmentConfigured ?? false))
                <div class="app-alert app-alert-warning flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="app-alert-title">{{ number_format($importedCount) }} leads imported ΓÇö enrichment not started</p>
                        <p class="app-alert-desc">Run AI enrichment when you're ready. Distribution to setters is a separate step after enrichment.</p>
                    </div>
                    @if($enrichmentConfigured ?? false)
                        <form method="POST" action="{{ route('admin.workflows.enrich', $workflow->id) }}">
                            @csrf
                            <button type="submit" class="app-btn app-btn-primary app-btn-sm whitespace-nowrap">Start enrichment</button>
                        </form>
                    @endif
                </div>
            @endif

            @if(($workflow->pending_verification_count ?? 0) > 0)
                <div class="app-alert app-alert-warning flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="app-alert-title"><span id="workspace-sync-workflow-pending-review">{{ $workflow->pending_verification_count }}</span> leads ready for review</p>
                        <p class="app-alert-desc">Approve to release to your team. Reject to skip bad data.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.workflows.approve-leads', $workflow->id) }}">
                        @csrf
                        @foreach($workflow->leads()->where('status', 'pending_verification')->pluck('id') as $leadId)
                            <input type="hidden" name="lead_ids[]" value="{{ $leadId }}">
                        @endforeach
                        <button type="submit" class="app-btn app-btn-primary app-btn-sm whitespace-nowrap">Approve all</button>
                    </form>
                </div>
            @endif

            @if($workflow->status === 'failed')
                <div class="app-alert app-alert-danger">{{ $workflow->error_message }}</div>
            @endif

            @if(!($enrichmentConfigured ?? true))
                <div class="app-alert app-alert-danger">
                    <p class="app-alert-title">AI enrichment is not configured</p>
                    <p class="app-alert-desc">{{ $enrichmentConfigMessage ?? 'Add GEMINI_API_KEY or OPENROUTER_API_KEY to the server .env file.' }}</p>
                </div>
            @elseif(($enrichmentStatus['gemini']['state'] ?? '') === 'depleted' && ($enrichmentStatus['openrouter']['state'] ?? '') === 'not_configured')
                <div class="app-alert app-alert-danger">
                    <p class="app-alert-title">Gemini credits depleted</p>
                    <p class="app-alert-desc">Top up in AI Studio or add OPENROUTER_API_KEY for fallback enrichment.</p>
                </div>
            @elseif(($enrichmentStatus['gemini']['state'] ?? '') === 'depleted')
                <div class="app-alert app-alert-warning">
                    <p class="app-alert-title">Gemini credits depleted ΓÇö using OpenRouter fallback</p>
                    <p class="app-alert-desc">Enrichment will skip Gemini and use OpenRouter until credits are restored.</p>
                </div>
            @endif

            @if(($retryableFailedLeads ?? 0) > 0)
                <div class="app-alert app-alert-warning flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <p class="app-alert-title">{{ number_format($retryableFailedLeads) }} leads failed enrichment</p>
                        <p class="app-alert-desc">Open the Failed tab below for details. After API keys are configured, retry the batch.</p>
                    </div>
                    @if($enrichmentConfigured ?? false)
                        <form method="POST" action="{{ route('admin.workflows.retry-failed', $workflow->id) }}">
                            @csrf
                            <button type="submit" class="app-btn app-btn-primary app-btn-sm whitespace-nowrap">Retry failed leads</button>
                        </form>
                    @endif
                </div>
            @endif

            <span id="workspace-sync-workflow-progress" class="hidden">{{ $attempted }}</span>
            <span id="workspace-sync-workflow-pending-review-2" class="hidden">{{ $workflow->pending_verification_count ?? 0 }}</span>
            <span id="workspace-sync-workflow-assigned" class="hidden">{{ $workflow->assigned_leads_count ?? 0 }}</span>
        </div>

        @include('workflows.partials.assign-to-team', [
            'workflow' => $workflow,
            'readyToDistribute' => $readyToDistribute,
            'teamLeads' => $teamLeads ?? collect(),
            'setters' => $setters ?? collect(),
            'setterTeamMetrics' => $setterTeamMetrics ?? [],
        ])

        <div class="app-card app-card-padded">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
                <h2 class="app-section-title">All enriched data</h2>
                <div class="app-filter-pills pipeline-lead-filters" id="pipeline-lead-filters" role="tablist">
                    <button type="button" class="is-active" data-filter="all">All</button>
                    <button type="button" data-filter="imported">Imported</button>
                    <button type="button" data-filter="enriched">Enriched</button>
                    <button type="button" data-filter="pending_verification">Needs review</button>
                    <button type="button" data-filter="completed">Released</button>
                    <button type="button" data-filter="failed">Failed</button>
                </div>
            </div>

            @if($workflow->total_leads > 0 || $leads->isNotEmpty())
                <x-data-table :paginator="$leads" min-width="960px">
                    <table>
                        <thead>
                            <tr>
                                <th>Business</th>
                                <th>Owner</th>
                                <th>Email</th>
                                <th>Social Media</th>
                                <th>Contact</th>
                                <th>Status</th>
                                <th class="text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody id="workspace-sync-pipeline-leads">
                            @forelse($leads as $lead)
                                @php($display = LeadContactDisplay::for($lead))
                                <tr data-lead-id="{{ $lead->id }}" data-lead-status="{{ $lead->status }}">
                                    <td>
                                        <a href="{{ \App\Support\LeadRoute::show($lead, true) }}" class="font-bold text-zinc-900 hover:underline">{{ $lead->business_name }}</a>
                                        @if($lead->city || $lead->state)
                                            <div class="text-xs text-zinc-400 mt-0.5">{{ $lead->city }}{{ $lead->city && $lead->state ? ', ' : '' }}{{ $lead->state }}</div>
                                        @endif
                                        @include('partials.lead-tag-chips', ['tags' => $lead->tags, 'list' => $lead->leadList, 'compact' => true])
                                    </td>
                                    <td class="text-sm text-zinc-600">{{ LeadContactDisplay::cell($display['owner']) ?: 'ΓÇö' }}</td>
                                    <td class="text-sm text-zinc-600">{{ LeadContactDisplay::cell($display['email']) ?: 'ΓÇö' }}</td>
                                    <td class="text-sm text-zinc-600">{{ LeadContactDisplay::cell($display['social_media']) ?: 'ΓÇö' }}</td>
                                    <td class="text-sm text-zinc-600">{{ LeadContactDisplay::cell($display['phone']) ?: 'ΓÇö' }}</td>
                                    <td>
                                        <x-lead-pipeline-badge :status="$lead->status" />
                                        @if($lead->status === 'failed' && $lead->error_message)
                                            <div class="text-xs text-rose-600 mt-1 max-w-xs">{{ Str::limit($lead->error_message, 120) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <a href="{{ \App\Support\LeadRoute::show($lead, true) }}" class="app-btn app-btn-secondary app-btn-sm">Details</a>
                                        @if($lead->status === 'pending_verification')
                                            <div class="flex items-center justify-end gap-1">
                                                <form method="POST" action="{{ route('admin.leads.approve', $lead->id) }}">
                                                    @csrf
                                                    <button type="submit" class="app-btn app-btn-success app-btn-sm">Approve</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.leads.reject', $lead->id) }}">
                                                    @csrf
                                                    <button type="submit" class="app-btn app-btn-ghost-danger app-btn-sm">Reject</button>
                                                </form>
                                            </div>
                                        @elseif($lead->status === 'completed')
                                            <span class="text-xs font-semibold text-emerald-700">Released</span>
                                        @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-8 text-slate-500">Leads are queued. They will appear here as enrichment runs.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </x-data-table>
            @else
                <div class="app-empty-state">
                    <div class="app-empty-state-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <p class="app-empty-state-title">No leads yet</p>
                    <p class="app-empty-state-desc">Leads appear here after import completes.</p>
                </div>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<div id="workspace-sync-page" class="hidden" data-workflow-id="{{ $workflow->id }}" aria-hidden="true"></div>
<script>
    document.body.dataset.workspaceWorkflowId = '{{ $workflow->id }}';

    document.getElementById('pipeline-lead-filters')?.addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-filter]');
        if (!btn) return;

        const filter = btn.dataset.filter;
        document.querySelectorAll('#pipeline-lead-filters button').forEach((b) => b.classList.toggle('is-active', b === btn));
        document.querySelectorAll('#workspace-sync-pipeline-leads tr').forEach((row) => {
            row.hidden = filter !== 'all' && row.dataset.leadStatus !== filter;
        });
    });
</script>
@endpush
