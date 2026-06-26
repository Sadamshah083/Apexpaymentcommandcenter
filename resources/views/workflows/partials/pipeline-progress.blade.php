@php
    $pending = $workflow->pending_verification_count ?? 0;
    $enriched = ($workflow->processed_leads ?? 0) + $pending;
    $assigned = $workflow->assigned_leads_count ?? 0;
    $total = max(1, $workflow->total_leads ?: 1);

    $importDone = $workflow->status !== 'mapping';
    $enrichActive = in_array($workflow->status, ['extracting', 'pending', 'paused']);
    $enrichDone = in_array($workflow->status, ['completed', 'paused']) || $enriched > 0;
    $reviewActive = $pending > 0;
    $reviewDone = $importDone && $pending === 0 && $enriched > 0;
    $distributeDone = $assigned > 0;

    $steps = [
        ['key' => 'import', 'label' => 'Import', 'done' => $importDone, 'active' => false, 'detail' => $workflow->total_leads . ' leads'],
        ['key' => 'enrich', 'label' => 'Enrich', 'done' => $workflow->status === 'completed' || ($enrichDone && !$enrichActive), 'active' => $enrichActive, 'detail' => $enrichActive ? ($enriched . ' / ' . $workflow->total_leads) : ($enrichDone ? 'Complete' : 'Waiting')],
        ['key' => 'review', 'label' => 'Review', 'done' => $reviewDone, 'active' => $reviewActive, 'detail' => $pending > 0 ? $pending . ' waiting' : ($reviewDone ? 'Cleared' : '—')],
        ['key' => 'distribute', 'label' => 'Distribute', 'done' => $distributeDone && $workflow->status === 'completed', 'active' => $assigned > 0 && $pending === 0, 'detail' => $assigned . ' assigned'],
    ];
@endphp

<div class="pipeline-steps" aria-label="Pipeline progress">
    @foreach($steps as $index => $step)
        @if($index > 0)
            <div class="pipeline-steps-connector {{ $steps[$index - 1]['done'] ? 'is-done' : '' }}" aria-hidden="true"></div>
        @endif
        <div class="pipeline-step {{ $step['done'] ? 'is-done' : '' }} {{ $step['active'] ? 'is-active' : '' }}">
            <div class="pipeline-step-dot">
                @if($step['done'])
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                @else
                    <span>{{ $index + 1 }}</span>
                @endif
            </div>
            <div class="pipeline-step-label">{{ $step['label'] }}</div>
            <div class="pipeline-step-detail" id="workspace-sync-step-{{ $step['key'] }}">{{ $step['detail'] }}</div>
        </div>
    @endforeach
</div>
