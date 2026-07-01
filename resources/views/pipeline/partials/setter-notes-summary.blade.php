@php
    $activities = $lead->setterStatusActivities();
@endphp

<div class="border-t border-zinc-100 pt-6 space-y-3">
    <div>
        <h2 class="app-section-title">Setter notes</h2>
        <p class="text-xs text-zinc-500 mt-0.5">
            Status updates and call notes from
            {{ $lead->setter?->name ?? 'the appointment setter' }}
            before handoff to closers.
        </p>
    </div>

    @if($activities->isNotEmpty())
        <ol class="lead-timeline">
            @foreach($activities as $activity)
                <x-lead-activity-timeline-item :activity="$activity" />
            @endforeach
        </ol>
    @elseif(filled($lead->handoff_notes))
        <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 px-4 py-3">
            <p class="text-sm text-zinc-700 whitespace-pre-wrap">{{ $lead->handoff_notes }}</p>
        </div>
    @endif
</div>
