<x-sidebar.section title="My CRM Hub">
    <x-sidebar.link
        :href="route('portal.dashboard')"
        label="Assigned Leads"
        :active="request()->routeIs('portal.dashboard') || request()->routeIs('portal.leads.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

<x-sidebar.section title="Validator Toolkit">
    <x-sidebar.link
        :href="route('portal.lists.index')"
        label="Bulk Email Verifier"
        :active="request()->routeIs('portal.lists.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'verify'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('portal.deliverability.index')"
        label="Domain Deliverability Scan"
        :active="request()->routeIs('portal.deliverability.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'domain'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('portal.content.index')"
        label="Outbound Spam Analyzer"
        :active="request()->routeIs('portal.content.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'spam'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('portal.reputation.index')"
        label="Sender Reputation Center"
        :active="request()->routeIs('portal.reputation.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'reputation'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

<x-sidebar.section title="Communications">
    <x-sidebar.link
        :href="route('portal.communications.index')"
        label="Communications Hub"
        :active="request()->routeIs('portal.communications.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'communications'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
