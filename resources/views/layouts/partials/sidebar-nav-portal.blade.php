<x-sidebar.section title="Pipeline">
    @php
        $user = auth()->user();
        $role = $user?->getWorkspaceRole();
        $teamMembers = $portalTeamMembers ?? collect();
    @endphp

    @if($role === 'appointment_setter')
        <x-sidebar.link :href="route('portal.setter.dashboard')" label="My Leads" :active="request()->routeIs('portal.setter.*') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
        </x-sidebar.link>
    @elseif($role === 'appointment_setter_team_lead')
        <x-sidebar.link :href="route('portal.setter-team.dashboard')" label="Assign Lead to Team" :active="request()->routeIs('portal.setter-team.*') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
        </x-sidebar.link>
        @if($teamMembers->isNotEmpty())
            <p class="sidebar-team-heading">Team</p>
            <div class="sidebar-team-members">
                @foreach($teamMembers as $member)
                    <a href="{{ $member['href'] }}"
                       @class(['sidebar-team-link', 'sidebar-team-link-active' => $member['active']])
                       data-turbo="false">
                        <span class="sidebar-team-link-name">{{ $member['name'] }}</span>
                        <span class="sidebar-team-link-count" title="Active leads">{{ $member['count'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    @elseif($role === 'closers_team_lead')
        <x-sidebar.link :href="route('portal.closer-team.dashboard')" label="Closer Team" :active="request()->routeIs('portal.closer-team.dashboard') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
        </x-sidebar.link>
        @if($teamMembers->isNotEmpty())
            <p class="sidebar-team-heading">Team</p>
            <div class="sidebar-team-members">
                @foreach($teamMembers as $member)
                    <a href="{{ $member['href'] }}"
                       @class(['sidebar-team-link', 'sidebar-team-link-active' => $member['active']])
                       data-turbo="false">
                        <span class="sidebar-team-link-name">{{ $member['name'] }}</span>
                        <span class="sidebar-team-link-count" title="Active leads">{{ $member['count'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
        <x-sidebar.link :href="route('portal.closer-team.queue')" label="Closer Queue" :active="request()->routeIs('portal.closer-team.queue')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
        </x-sidebar.link>
    @elseif($role === 'closer')
        <x-sidebar.link :href="route('portal.closer.dashboard')" label="My Closer Leads" :active="request()->routeIs('portal.closer.*') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
        </x-sidebar.link>
    @endif
</x-sidebar.section>
