<x-sidebar.section title="Pipeline">
    @php
        $user = auth()->user();
        $role = $user?->getWorkspaceRole();
        $workspaceId = $user?->current_workspace_id;
    @endphp

    @if ($role === 'appointment_setter')
        @if ($user->canAccessPortalModule('setter_leads', $workspaceId))
            <x-sidebar.link :href="route('portal.setter.dashboard')" label="My Leads" icon-name="leads"
                :active="request()->routeIs('portal.setter.*') || request()->routeIs('portal.leads.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($user->canAccessPortalModule('performance', $workspaceId))
            <x-sidebar.link :href="route('portal.performance')" label="Performance" icon-name="dashboard"
                :active="request()->routeIs('portal.performance')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'dashboard'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    @elseif($role === 'appointment_setter_team_lead')
        @if ($user->canAccessPortalModule('setter_team', $workspaceId))
            <x-sidebar.link :href="route('portal.setter-team.dashboard')" label="Setter Team" icon-name="team"
                :active="request()->routeIs('portal.setter-team.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    @elseif($role === 'closers_team_lead')
        @if ($user->canAccessPortalModule('closer_team', $workspaceId))
            <x-sidebar.link :href="route('portal.closer-team.dashboard')" label="Closer Team" icon-name="team"
                :active="request()->routeIs('portal.closer-team.dashboard') || request()->routeIs('portal.closer-team.index')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($user->canAccessPortalModule('closer_queue', $workspaceId))
            <x-sidebar.link :href="route('portal.closer-team.queue')" label="Closer Queue" icon-name="pipeline"
                :active="request()->routeIs('portal.closer-team.queue')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    @elseif($role === 'closer')
        @if ($user->canAccessPortalModule('closer_leads', $workspaceId))
            <x-sidebar.link :href="route('portal.closer.dashboard')" label="My Closer Leads" icon-name="leads"
                :active="request()->routeIs('portal.closer.*') || request()->routeIs('portal.leads.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($user->canAccessPortalModule('closer_pipeline', $workspaceId))
            <x-sidebar.link :href="route('portal.pipeline')" label="Pipeline" icon-name="pipeline"
                :active="request()->routeIs('portal.pipeline')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    @endif
</x-sidebar.section>

@if ($user?->canAccessPortalModule('communications', $user?->current_workspace_id))
    <x-sidebar.section title="Communications">
        <x-sidebar.link :href="route('portal.communications.index')" label="Communications" icon-name="phone"
            :exclude-prefixes="['/portal/communications/monitoring', '/portal/communications/notes']"
            :active="request()->routeIs('portal.communications.*') && ! request()->routeIs('portal.communications.monitoring*', 'portal.communications.notes')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'phone'])</x-slot:icon>
        </x-sidebar.link>
        <x-sidebar.link :href="route('portal.communications.notes')" label="Call Notes" icon-name="notes"
            :active="request()->routeIs('portal.communications.notes')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'notes'])</x-slot:icon>
        </x-sidebar.link>
        @if (app(\App\Services\Communications\CommunicationsAccessService::class)->canViewCallMonitoring($user, 'portal.'))
            <x-sidebar.link
                :href="route('portal.communications.monitoring')"
                label="Call Monitoring"
                icon-name="phone"
                :active="request()->routeIs('portal.communications.monitoring*')"
                data-call-monitoring-nav
                data-call-monitoring-poll-url="{{ route('portal.communications.monitoring.live') }}"
                data-call-monitoring-stream-url="{{ route('portal.communications.monitoring.stream') }}">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'phone'])</x-slot:icon>
                <x-slot:badge>
                    <span class="sidebar-live-chip sidebar-live-chip--pink is-empty" title="Active in call" data-call-monitoring-nav-incall hidden>0</span>
                    <span class="sidebar-live-chip sidebar-live-chip--blue is-empty" title="Ringing" data-call-monitoring-nav-waiting hidden>0</span>
                </x-slot:badge>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif
