<x-sidebar.section title="Lead Pipeline">
    <x-sidebar.link
        :href="route('admin.workflows.index')"
        label="Import & Overview"
        :active="request()->routeIs('admin.workflows.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>

@if(auth()->user()?->isSuperAdmin())
<x-sidebar.section title="Workspace Admin">
    <x-sidebar.link
        :href="route('admin.workspaces.index')"
        label="User Management"
        :active="request()->routeIs('admin.workspaces.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif
