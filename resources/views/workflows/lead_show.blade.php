@extends('layouts.portal')

@section('title', $lead->business_name)

@section('content')
@php
    use App\Support\SalesOps;
@endphp
<div class="app-page space-y-6">
    <div class="app-page-header flex items-center justify-between">
        <div>
            <h1 class="app-page-title">{{ $lead->business_name }}</h1>
            <p class="app-page-subtitle">
                {{ SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}
                @if($lead->setter_status)
                    &bull; Setter: {{ SalesOps::setterStatusLabel($lead->setter_status) }}
                @endif
                @if($lead->closer_status)
                    &bull; Closer: {{ SalesOps::closerStatusLabel($lead->closer_status) }}
                @endif
            </p>
        </div>
        <a href="{{ route($user->portalDashboardRoute()) }}" class="app-btn app-btn-secondary text-sm">Back</a>
    </div>

    @if($lead->isSetterLocked() && $user->isAppointmentSetter($workspace->id))
        <div class="app-alert app-alert-warning">
            <p class="app-alert-title">This lead has been handed off to the closers team and is read-only for setters.</p>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-6">
            <div class="app-card app-card-padded space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><span class="text-zinc-500">Owner</span><p class="font-semibold">{{ $lead->owner_name ?: '—' }}</p></div>
                    <div><span class="text-zinc-500">Email</span><p class="font-semibold">{{ $lead->direct_email ?: $lead->input_email ?: '—' }}</p></div>
                    <div><span class="text-zinc-500">Phone</span><p class="font-semibold">{{ $lead->direct_phone ?: $lead->input_phone ?: '—' }}</p></div>
                    <div><span class="text-zinc-500">Website</span><p class="font-semibold">{{ $lead->website ?: '—' }}</p></div>
                    <div><span class="text-zinc-500">Location</span><p class="font-semibold">{{ trim(($lead->city ?: '').', '.($lead->state ?: ''), ', ') ?: '—' }}</p></div>
                    <div><span class="text-zinc-500">Processor</span><p class="font-semibold">{{ $lead->payment_processor ?: '—' }}</p></div>
                </div>

                @if($canEditSetter && ! $lead->isSetterLocked())
                    <form method="POST" action="{{ route('portal.leads.setter-status', $lead->id) }}" class="space-y-4 border-t border-zinc-100 pt-6">
                        @csrf
                        <h2 class="app-section-title">Update setter status</h2>
                        <select name="setter_status" required class="app-input max-w-xs">
                            @foreach($setterStatuses as $value => $label)
                                <option value="{{ $value }}" @selected($lead->setter_status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <textarea name="notes" rows="3" class="app-input" placeholder="Notes (optional)">{{ $lead->notes }}</textarea>
                        <button type="submit" class="app-btn app-btn-primary">Save status</button>
                    </form>
                @endif

                @if($canEditCloser && ! $lead->isCloserLocked())
                    <form method="POST" action="{{ route('portal.leads.closer-status', $lead->id) }}" class="space-y-4 border-t border-zinc-100 pt-6">
                        @csrf
                        <h2 class="app-section-title">Update closer status</h2>
                        <select name="closer_status" required class="app-input max-w-xs">
                            @foreach($closerStatuses as $value => $label)
                                @if($value !== 'sale_made' || $user->isCloser($workspace->id) || $user->isSuperAdmin($workspace->id) || $user->isAdmin($workspace->id))
                                    <option value="{{ $value }}" @selected($lead->closer_status === $value)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                        <textarea name="notes" rows="3" class="app-input" placeholder="Notes (optional)">{{ $lead->notes }}</textarea>
                        <button type="submit" class="app-btn app-btn-primary">Save status</button>
                    </form>
                @endif

                @if($lead->handoff_notes)
                    <div class="border-t border-zinc-100 pt-6">
                        <h2 class="app-section-title">Handoff notes</h2>
                        <p class="text-sm text-zinc-700 whitespace-pre-wrap">{{ $lead->handoff_notes }}</p>
                    </div>
                @endif
            </div>

            @if($lead->markdown_report)
                <div class="app-card app-card-padded prose prose-sm max-w-none">
                    {!! \Illuminate\Support\Str::markdown($lead->markdown_report) !!}
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-4">Activity</h2>
                <div class="space-y-3">
                    @forelse($lead->activities as $activity)
                        <div class="text-sm border-b border-zinc-100 pb-3">
                            <p class="font-semibold text-zinc-800">{{ $activity->user?->name ?? 'System' }}</p>
                            <p class="text-zinc-600">{{ $activity->notes ?: $activity->type }}</p>
                            <p class="text-xs text-zinc-400">{{ $activity->created_at?->diffForHumans() }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">No activity yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
