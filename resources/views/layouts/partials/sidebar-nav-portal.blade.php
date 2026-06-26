@php
    $user = auth()->user();
    $isAe = $user?->isAccountExecutive();
@endphp

<x-sidebar.section title="SDR Workspace">
    <x-sidebar.link
        :href="route('portal.dashboard')"
        label="My Lead Pool"
        :active="request()->routeIs('portal.dashboard') || request()->routeIs('portal.leads.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('portal.performance')"
        label="Daily Performance"
        :active="request()->routeIs('portal.performance')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'dashboard'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

@if($isAe)
<x-sidebar.section title="Account Executive">
    <x-sidebar.link
        :href="route('portal.ae.pipeline')"
        label="Meetings & Proposals"
        :active="request()->routeIs('portal.ae.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif
