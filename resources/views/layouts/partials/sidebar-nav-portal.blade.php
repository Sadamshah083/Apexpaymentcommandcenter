<x-sidebar.section title="Pipeline">
    @php
        $user = auth()->user();
        $role = $user?->getWorkspaceRole();
    @endphp

    @if ($role === 'appointment_setter')
        <x-sidebar.link :href="route('portal.setter.dashboard')" label="My Leads" :active="request()->routeIs('portal.setter.*') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
        </x-sidebar.link>
    @elseif($role === 'appointment_setter_team_lead')
        <x-sidebar.link :href="route('portal.setter-team.dashboard')" label="Setter Team" :active="request()->routeIs('portal.setter-team.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
        </x-sidebar.link>
    @elseif($role === 'closers_team_lead')
        <x-sidebar.link :href="route('portal.closer-team.dashboard')" label="Closer Team" :active="request()->routeIs('portal.closer-team.dashboard')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
        </x-sidebar.link>
        <x-sidebar.link :href="route('portal.closer-team.queue')" label="Closer Queue" :active="request()->routeIs('portal.closer-team.queue')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
        </x-sidebar.link>
    @elseif($role === 'closer')
        <x-sidebar.link :href="route('portal.closer.dashboard')" label="My Closer Leads" :active="request()->routeIs('portal.closer.*') || request()->routeIs('portal.leads.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
        </x-sidebar.link>
    @endif
</x-sidebar.section>

<x-sidebar.section title="Communications">
    <x-sidebar.link :href="route('portal.communications.index', ['panel' => 'dialer'])" label="Phone Dialer"
        :active="request()->routeIs('portal.communications.*') && request('panel') === 'dialer'">
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'phone'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link :href="route('portal.communications.index')" label="Inbox & Calls"
        :active="request()->routeIs('portal.communications.*') && request('panel') !== 'dialer'">
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'communications'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
