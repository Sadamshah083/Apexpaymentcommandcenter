@php
    use App\Support\LeadContactDisplay;
    use App\Support\SalesOps;

    $readOnly = $readOnly ?? false;
    $showAssignee = $showAssignee ?? false;
    $editableSetterStatus = $editableSetterStatus ?? false;
    $showSetterNotes = $showSetterNotes ?? false;
    $liveSync = $liveSync ?? false;
    $setterStatuses = $setterStatuses ?? config('sales_ops.setter_statuses', []);
@endphp

@if ($leads->isEmpty())
    <div class="app-empty-state">
        <p class="app-empty-state-title">No leads found</p>
    </div>
@else
    <x-data-table :paginator="$leads" min-width="640px">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Email</th>
                    <th>Social Media</th>
                    <th>Contact</th>
                    @if ($showAssignee)
                        <th>Assignee</th>
                    @endif
                    <th>Phase</th>
                    <th>Status</th>
                    @if ($showSetterNotes)
                        <th>Setter notes</th>
                    @endif
                    @if ($editableSetterStatus)
                        <th>Update</th>
                    @endif
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody
                @if ($liveSync)
                    id="workspace-sync-portal-leads-body"
                    data-sync-mode="patch"
                    data-status-column="{{ $statusColumn ?? 'setter' }}"
                    data-show-assignee="{{ $showAssignee ? '1' : '0' }}"
                    data-show-setter-notes="{{ $showSetterNotes ? '1' : '0' }}"
                    data-show-editable-setter="{{ $editableSetterStatus ? '1' : '0' }}"
                @endif
            >
                @foreach ($leads as $lead)
                    @php
                        $contact = LeadContactDisplay::for($lead);
                    @endphp
                    <tr @if ($liveSync) data-lead-id="{{ $lead->id }}" @endif>
                        <td @if ($liveSync) data-col="business" @endif>
                            <a href="{{ route('portal.leads.show', $lead->id) }}" data-turbo="false"
                                class="font-bold text-zinc-900 hover:underline">{{ $lead->business_name }}</a>
                            @include('partials.campaign-chip', ['campaign' => $lead->campaign ?? null, 'compact' => true, 'linked' => false])
                        </td>
                        <td class="text-sm text-zinc-600" @if ($liveSync) data-col="email" @endif>{{ LeadContactDisplay::cell($contact['email']) ?: '—' }}</td>
                        <td class="text-sm text-zinc-600" @if ($liveSync) data-col="social" @endif>{{ LeadContactDisplay::cell($contact['social_media']) ?: '—' }}</td>
                        <td class="text-sm text-zinc-600" @if ($liveSync) data-col="contact" @endif>{{ LeadContactDisplay::cell($contact['phone']) ?: '—' }}</td>
                        @if ($showAssignee)
                            <td class="text-sm" @if ($liveSync) data-col="assignee" @endif>
                                @if ($lead->pipeline_phase === 'enriched' && ! $lead->assigned_user_id)
                                    <span class="text-amber-700 font-medium">Unassigned</span>
                                @else
                                    {{ $lead->assignee?->name ?? ($lead->setter?->name ?? '—') }}
                                @endif
                            </td>
                        @endif
                        <td class="text-sm" @if ($liveSync) data-col="phase" @endif>{{ SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</td>
                        <td class="text-sm" @if ($liveSync) data-col="status" @endif>
                            @if (($statusColumn ?? 'setter') === 'closer')
                                {{ SalesOps::closerStatusLabel($lead->closer_status) }}
                            @elseif(($statusColumn ?? 'setter') === 'both')
                                {{ SalesOps::setterStatusLabel($lead->setter_status) }}
                                @if ($lead->closer_status)
                                    <span class="text-zinc-400"> /
                                        {{ SalesOps::closerStatusLabel($lead->closer_status) }}</span>
                                @endif
                            @else
                                {{ SalesOps::setterStatusLabel($lead->setter_status) }}
                            @endif
                        </td>
                        @if ($showSetterNotes)
                            <td class="text-sm text-zinc-600 max-w-xs align-top" @if ($liveSync) data-col="setter_notes" @endif>
                                @if (filled($lead->handoff_notes))
                                    <p class="line-clamp-3 whitespace-pre-wrap">{{ $lead->handoff_notes }}</p>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                        @endif
                        @if ($editableSetterStatus)
                            <td class="min-w-[220px] align-top" @if ($liveSync) data-col="update" @endif>
                                @if ($lead->pipeline_phase === 'with_setter' && ! $lead->isSetterLocked())
                                    <details class="setter-status-details">
                                        <summary
                                            class="text-xs font-semibold text-indigo-600 cursor-pointer select-none">
                                            Change status</summary>
                                        <div class="mt-2">
                                            @include('pipeline.partials.setter-status-form', [
                                                'lead' => $lead,
                                                'setterStatuses' => $setterStatuses,
                                                'compact' => true,
                                            ])
                                        </div>
                                    </details>
                                @else
                                    <span class="text-xs text-zinc-400">—</span>
                                @endif
                            </td>
                        @endif
                        <td class="text-right align-top">
                            <a href="{{ route('portal.leads.show', $lead->id) }}" data-turbo="false"
                                class="app-btn app-btn-secondary app-btn-sm">Open</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-data-table>
@endif
