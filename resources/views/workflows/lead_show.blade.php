@extends($isAdminView ? 'layouts.admin' : 'layouts.portal')

@section('title', $lead->business_name)

@section('content')
@php
    use App\Support\LeadContactDisplay;
    use App\Support\LeadRoute;
    use App\Support\SalesOps;

    $contact = LeadContactDisplay::for($lead);
@endphp
<div id="workspace-sync-page" class="hidden" data-lead-id="{{ $lead->id }}" aria-hidden="true"></div>
<div class="app-page space-y-6">
    <div class="app-page-header flex items-center justify-between">
        <div>
            <h1 class="app-page-title">{{ $lead->business_name }}</h1>
            <p class="app-page-subtitle" id="workspace-sync-lead-subtitle">
                <span id="workspace-sync-lead-phase">{{ SalesOps::pipelinePhaseLabel($lead->pipeline_phase) }}</span>
                @if($lead->setter_status)
                    &bull; Setter: <span id="workspace-sync-lead-setter-status">{{ SalesOps::setterStatusLabel($lead->setter_status) }}</span>
                @else
                    <span id="workspace-sync-lead-setter-status" class="hidden"></span>
                @endif
                @if($lead->closer_status)
                    &bull; Closer: <span id="workspace-sync-lead-closer-status">{{ SalesOps::closerStatusLabel($lead->closer_status) }}</span>
                @else
                    <span id="workspace-sync-lead-closer-status" class="hidden"></span>
                @endif
                @if($lead->setter)
                    &bull; Assigned: <span id="workspace-sync-lead-assignee">{{ $lead->setter->name }}</span>
                @else
                    <span id="workspace-sync-lead-assignee" class="hidden"></span>
                @endif
            </p>
            @include('partials.campaign-chip', ['campaign' => $lead->campaign, 'compact' => true])
            @if($lead->leadList)
                <div class="text-[10px] text-zinc-400 mt-0.5">List: <span class="font-medium text-zinc-600">{{ $lead->leadList->name }}</span></div>
            @endif
        </div>
        @if($isAdminView)
            <a href="{{ route('admin.workflows.show', $lead->workflow_id) }}" class="app-btn app-btn-secondary text-sm">Back to import</a>
        @else
            <a href="{{ route($user->portalDashboardRoute()) }}" class="app-btn app-btn-secondary text-sm" data-turbo="false">Back</a>
        @endif
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
                    <div><span class="text-zinc-500">Owner</span><p class="font-semibold">{{ LeadContactDisplay::label($contact['owner'], '') }}</p></div>
                    <div><span class="text-zinc-500">Email</span><p class="font-semibold break-all">{{ LeadContactDisplay::label($contact['email'], '') }}</p></div>
                    <div><span class="text-zinc-500">Social Media</span><p class="font-semibold break-all">{{ LeadContactDisplay::label($contact['social_media'], '') }}</p></div>
                    <div><span class="text-zinc-500">Contact</span><p class="font-semibold">{{ LeadContactDisplay::label($contact['phone'], '') }}</p></div>
                    <div><span class="text-zinc-500">Website</span><p class="font-semibold break-all">{{ LeadContactDisplay::label($contact['website'], '') }}</p></div>
                    <div><span class="text-zinc-500">Address</span><p class="font-semibold">{{ LeadContactDisplay::label($contact['address']) }}</p></div>
                    <div><span class="text-zinc-500">Location</span><p class="font-semibold">{{ LeadContactDisplay::label($contact['location']) }}</p></div>
                    <div><span class="text-zinc-500">Processor</span><p class="font-semibold">{{ LeadContactDisplay::label($contact['processor']) }}</p></div>
                    @if($contact['pos_system'])
                        <div><span class="text-zinc-500">POS / Booking</span><p class="font-semibold">{{ $contact['pos_system'] }}</p></div>
                    @endif
                </div>

                @if($canEditSetter)
                    <div class="border-t border-zinc-100 pt-6 space-y-3">
                        <h2 class="app-section-title">Update setter status</h2>
                        <p class="text-xs text-zinc-500">Pick a status and add a note about what happened. It appears on the timeline below.</p>
                        @include('pipeline.partials.setter-status-form', [
                            'lead' => $lead,
                            'setterStatuses' => $setterStatuses,
                            'compact' => false,
                            'routePrefix' => $isAdminView ? 'admin' : 'portal',
                        ])
                    </div>
                @endif

                @if($showSetterHistory ?? false)
                    @include('pipeline.partials.setter-notes-summary', ['lead' => $lead])
                @endif

                @if($canEditCloser && ! $lead->isCloserLocked())
                    <form method="POST" action="{{ route(LeadRoute::name('closer-status', $isAdminView), $lead->id) }}" class="space-y-4 border-t border-zinc-100 pt-6">
                        @csrf
                        <h2 class="app-section-title">Update closer status</h2>
                        <select name="closer_status" required class="app-input max-w-xs">
                            @foreach($closerStatuses as $value => $label)
                                @if($value !== 'sale_made' || $user->isCloser($workspace->id) || $user->isSuperAdmin($workspace->id) || $user->isAdmin($workspace->id))
                                    <option value="{{ $value }}" @selected($lead->closer_status === $value)>{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                        <textarea name="notes" rows="3" class="app-input" placeholder="What happened on this call or follow-up? (optional)"></textarea>
                        <button type="submit" class="app-btn app-btn-primary">Save status</button>
                    </form>
                @endif
            </div>

            @if(LeadContactDisplay::shouldDisplayEnrichmentReport($lead->markdown_report))
                <div class="app-card app-card-padded prose prose-sm max-w-none">
                    {!! \Illuminate\Support\Str::markdown($lead->markdown_report) !!}
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @if($isAdminView ?? false)
                @if($lead->campaign)
                    <div class="app-card app-card-padded">
                        <h2 class="app-section-title mb-2">Campaign</h2>
                        <p class="text-sm text-zinc-600 mb-3">This lead belongs to the import campaign below.</p>
                        @include('partials.campaign-chip', ['campaign' => $lead->campaign])
                        <a href="{{ route('admin.campaigns.show', $lead->campaign) }}" class="app-link text-sm mt-3 inline-block">Manage campaign</a>
                    </div>
                @endif
            @endif

            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-3">Lead metrics</h2>
                <dl class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Tier</dt>
                        <dd id="workspace-sync-lead-tier" class="font-semibold">{{ SalesOps::tierLabel($lead->tier) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Stage</dt>
                        <dd id="workspace-sync-lead-stage" class="font-semibold">{{ SalesOps::crmStageLabel($lead->stage) }}</dd>
                    </div>
                    <div class="col-span-2">
                        <dt class="text-zinc-500">Contact attempts</dt>
                        <dd id="workspace-sync-lead-attempts" class="font-semibold">{{ $lead->contact_attempts }} contact attempt(s)</dd>
                    </div>
                </dl>
            </div>

            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-1">Status timeline</h2>
                <p class="app-section-desc mb-4">Every setter and closer status change on this lead.</p>

                @php
                    $statusActivities = $lead->activities->filter(fn ($a) => $a->isStatusChange());
                @endphp

                @if($statusActivities->isNotEmpty())
                    <ol class="lead-timeline">
                        @foreach($statusActivities as $activity)
                            <x-lead-activity-timeline-item :activity="$activity" />
                        @endforeach
                    </ol>
                @else
                    <p class="text-sm text-zinc-500">No status changes recorded yet.</p>
                @endif
            </div>

            <div class="app-card app-card-padded">
                <h2 class="app-section-title mb-4">All activity</h2>
                <div id="workspace-sync-lead-activities">
                @if($lead->activities->isNotEmpty())
                    @foreach($lead->activities as $activity)
                        @php
                            $activityTypes = config('sales_ops.activity_types', []);
                            $typeLabel = $activityTypes[$activity->type] ?? $activity->type;
                        @endphp
                        <div class="text-xs border-b border-zinc-100 py-2 last:border-0">
                            <span class="font-semibold text-zinc-700">{{ $typeLabel }}</span>
                            @if($activity->user?->name)
                                <span class="text-zinc-400"> · {{ $activity->user->name }}</span>
                            @endif
                            <span class="text-zinc-400"> · {{ $activity->created_at?->diffForHumans() }}</span>
                            @if($activity->notes)
                                <div class="text-zinc-400 mt-0.5">{{ $activity->notes }}</div>
                            @endif
                        </div>
                    @endforeach
                @else
                    <p class="text-xs text-zinc-400 italic">No activity logged yet.</p>
                @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
