<x-sidebar.section title="My CRM Hub">
    <x-sidebar.link
        :href="route('portal.dashboard')"
        label="Assigned Leads"
        :active="request()->routeIs('portal.dashboard') || request()->routeIs('portal.leads.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
