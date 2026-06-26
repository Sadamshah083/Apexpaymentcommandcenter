<x-sidebar.section title="Sales Operations">
    <x-sidebar.link
        :href="route('admin.sales-ops.index')"
        label="Command Center"
        :active="request()->routeIs('admin.sales-ops.index')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'dashboard'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.sales-ops.performance')"
        label="Team Performance"
        :active="request()->routeIs('admin.sales-ops.performance')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.sales-ops.distribution')"
        label="Lead Distribution"
        :active="request()->routeIs('admin.sales-ops.distribution')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'leads'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.sales-ops.reactivation')"
        label="Reactivation Program"
        :active="request()->routeIs('admin.sales-ops.reactivation')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

<x-sidebar.section title="Data Acquisition">
    <x-sidebar.link
        :href="route('admin.workflows.index')"
        label="Import Leads"
        :active="request()->routeIs('admin.workflows.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.business-research.index')"
        label="Merchant Research"
        :active="request()->routeIs('admin.business-research.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'verify'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

<x-sidebar.section title="Validator Toolkit">
    <x-sidebar.link
        :href="route('admin.lists.index')"
        label="Bulk Email Verifier"
        :active="request()->routeIs('admin.lists.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'verify'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.deliverability.index')"
        label="Domain Deliverability Scan"
        :active="request()->routeIs('admin.deliverability.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'domain'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.content.index')"
        label="Outbound Spam Analyzer"
        :active="request()->routeIs('admin.content.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'spam'])</x-slot:icon>
    </x-sidebar.link>
    <x-sidebar.link
        :href="route('admin.reputation.index')"
        label="Sender Reputation Center"
        :active="request()->routeIs('admin.reputation.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'reputation'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

<x-sidebar.section title="Workspace Admin">
    <x-sidebar.link
        :href="route('admin.workspaces.index')"
        label="Collaborators & Contexts"
        :active="request()->routeIs('admin.workspaces.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
