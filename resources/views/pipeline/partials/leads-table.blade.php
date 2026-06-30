@php
    use App\Support\SalesOps;
    $readOnly = $readOnly ?? false;
    $showAssignee = $showAssignee ?? false;
@endphp

@if($leads->isEmpty())
    <div class="app-empty-state">
        <p class="app-empty-state-title">No leads found</p>
    </div>
@else
    <x-data-table :paginator="$leads" min-width="640px">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Contact</th>
                    @if($showAssignee)
                        <th>Assignee</th>
                    @endif
                    <th>Phase</th>
                    <th>Status</th>
                    <th class="text-right">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leads as $lead)
                    <tr>
                        <td>
                            <a href="{{ route('portal.leads.show', $lead->id) }}" class="font-bold text-zinc-900 hover:underline">{{ $lead->business_name }}</a>
                        </td>
                        <td class="text-sm text-zinc-600">{{ $lead->direct_email ?: $lead->input_email ?: '—' }}</td>
                        @if($showAssignee)
                            <td class="text-sm">{{ $lead->assignee?->name ?? $lead->setter?->name ?? '—' }}</td>
                        @endif
                        <td class="text-sm">{{ SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</td>
                        <td class="text-sm">
                            @if(($statusColumn ?? 'setter') === 'closer')
                                {{ SalesOps::closerStatusLabel($lead->closer_status) }}
                            @elseif(($statusColumn ?? 'setter') === 'both')
                                {{ SalesOps::setterStatusLabel($lead->setter_status) }}
                                @if($lead->closer_status)
                                    <span class="text-zinc-400"> / {{ SalesOps::closerStatusLabel($lead->closer_status) }}</span>
                                @endif
                            @else
                                {{ SalesOps::setterStatusLabel($lead->setter_status) }}
                            @endif
                        </td>
                        <td class="text-right">
                            <a href="{{ route('portal.leads.show', $lead->id) }}" class="app-btn app-btn-secondary app-btn-sm">Open</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-data-table>
@endif
