@php
    use App\Support\SalesOps;
    use App\Support\WorkflowAssignmentRoles;

    $readyCount = (int) ($workflow->ready_to_assign_count ?? $workflow->ready_to_distribute_count ?? $readyToDistribute ?? 0);
    $totalLeads = (int) ($workflow->total_leads ?? 0);
    $assignedCount = (int) ($workflow->assigned_leads_count ?? max(0, $totalLeads - $readyCount));
    $teamLeads = $teamLeads ?? collect();
    $setterTeamLeadRole = WorkflowAssignmentRoles::setterTeamLeadRole();
    $defaultTeamLead = $teamLeads->first(fn ($user) => $user->pivot->role === $setterTeamLeadRole) ?? $teamLeads->first();
    $defaultTeamLeadId = old('team_lead_id', $defaultTeamLead?->id);
    $defaultCount = old('lead_count', min($readyCount, max(1, $readyCount)));
    $enrichmentDone = $totalLeads > 0 && $readyCount === 0 && ($workflow->enriched_leads ?? 0) >= $totalLeads;
@endphp

@if($totalLeads > 0 && ! ($workflow->isProcessing() ?? false))
    <div class="app-card app-card-padded border-indigo-200 bg-indigo-50/30 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
            <div>
                <h3 class="app-section-title text-indigo-900">Assignment progress</h3>
                <p class="app-section-desc">
                    Assign enriched leads to an Appointment Setter Team Lead. Leads are distributed across their active setters.
                </p>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center min-w-[280px]">
                <div class="rounded-lg bg-white/80 border border-indigo-100 px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Total</p>
                    <p class="text-lg font-bold text-zinc-900">{{ number_format($totalLeads) }}</p>
                </div>
                <div class="rounded-lg bg-white/80 border border-indigo-100 px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Enriched</p>
                    <p class="text-lg font-bold text-zinc-900">{{ number_format($workflow->enriched_leads ?? 0) }}</p>
                </div>
                <div class="rounded-lg bg-white/80 border border-indigo-100 px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Assigned</p>
                    <p class="text-lg font-bold text-emerald-700">{{ number_format($assignedCount) }}</p>
                </div>
                <div class="rounded-lg bg-white/80 border border-indigo-100 px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Unassigned</p>
                    <p class="text-lg font-bold text-amber-700">{{ number_format($readyCount) }}</p>
                </div>
            </div>
        </div>

        @if($enrichmentDone && $readyCount === 0)
            <div class="app-alert app-alert-success">
                <p class="app-alert-title">All {{ number_format($totalLeads) }} leads assigned and released</p>
                <p class="app-alert-desc">This import is complete. Assigned leads now appear in Active leads on the overview page.</p>
            </div>
        @elseif($readyCount > 0)
            <form method="POST" action="{{ route('admin.workflows.assign-leads', $workflow->id) }}" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                @csrf

                <div class="app-field md:col-span-1">
                    <label for="workflow-team-lead-id" class="app-label">Team lead</label>
                    <select name="team_lead_id" id="workflow-team-lead-id" class="app-input w-full" required @disabled($teamLeads->isEmpty())>
                        <option value="">Select team lead…</option>
                        @foreach($teamLeads as $teamLead)
                            <option value="{{ $teamLead->id }}" @selected((string) $defaultTeamLeadId === (string) $teamLead->id)>
                                {{ $teamLead->name }} ({{ SalesOps::roleLabel($teamLead->pivot->role) }})
                            </option>
                        @endforeach
                    </select>
                    @error('team_lead_id')
                        <p class="text-xs text-rose-600 font-semibold mt-1">{{ $message }}</p>
                    @enderror
                    @if($teamLeads->isEmpty())
                        <p class="text-xs text-amber-700 mt-1">No team leads in this workspace.</p>
                    @else
                        <p class="text-xs text-zinc-500 mt-1">Use Appointment Setter Team Lead for enriched import leads.</p>
                    @endif
                </div>

                <div class="app-field md:col-span-1">
                    <label for="workflow-lead-count" class="app-label">Number of leads (max {{ number_format($readyCount) }})</label>
                    <input
                        type="number"
                        name="lead_count"
                        id="workflow-lead-count"
                        class="app-input w-full"
                        min="1"
                        max="{{ max(1, $readyCount) }}"
                        value="{{ $defaultCount }}"
                        required
                    >
                    @error('lead_count')
                        <p class="text-xs text-rose-600 font-semibold mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-wrap gap-2 md:col-span-1">
                    <button
                        type="submit"
                        class="app-btn app-btn-primary w-full sm:w-auto"
                        @disabled($teamLeads->isEmpty())
                    >
                        Assign {{ number_format(min($defaultCount, $readyCount)) }} of {{ number_format($readyCount) }}
                    </button>
                </div>
            </form>
        @endif
    </div>
@endif
