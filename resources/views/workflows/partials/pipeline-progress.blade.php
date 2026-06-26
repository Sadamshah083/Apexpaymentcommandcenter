@php
    $steps = \App\Support\PipelineProgress::steps($workflow);
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
