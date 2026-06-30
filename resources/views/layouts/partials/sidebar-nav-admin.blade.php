@php
    $user = auth()->user();
    $workspaceId = $user?->current_workspace_id;

    $can = fn (string $module) => $user?->canAccessAdminModule($module, $workspaceId);

    $leadPipelineModules = ['lead_pipeline', 'lead_tags'];
    $emailToolkitModules = ['email_lists', 'deliverability', 'content_analyzer', 'reputation'];
    $showLeadPipeline = collect($leadPipelineModules)->contains(fn (string $module) => $can($module));
    $showEmailToolkit = collect($emailToolkitModules)->contains(fn (string $module) => $can($module));
@endphp

@if($showLeadPipeline)
<x-sidebar.section title="Lead Pipeline">
    @if($can('lead_pipeline'))
    <x-sidebar.link
        :href="route('admin.workflows.index')"
        label="Import & Overview"
        :active="request()->routeIs('admin.workflows.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
    @endif
    @if($can('lead_tags'))
    <x-sidebar.link
        :href="route('admin.lead-tags.index')"
        label="Lead Tags"
        :active="request()->routeIs('admin.lead-tags.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
    </x-sidebar.link>
    @endif
</x-sidebar.section>
@endif

@if($showEmailToolkit)
<x-sidebar.section title="Email Toolkit">
    @if($can('email_lists'))
    <x-sidebar.link
        :href="route('admin.lists.index')"
        label="Bulk Email Verifier"
        :active="request()->routeIs('admin.lists.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'verify'])</x-slot:icon>
    </x-sidebar.link>
    @endif
    @if($can('deliverability'))
    <x-sidebar.link
        :href="route('admin.deliverability.index')"
        label="Domain Deliverability Scan"
        :active="request()->routeIs('admin.deliverability.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'domain'])</x-slot:icon>
    </x-sidebar.link>
    @endif
    @if($can('content_analyzer'))
    <x-sidebar.link
        :href="route('admin.content.index')"
        label="Outbound Spam Analyzer"
        :active="request()->routeIs('admin.content.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'spam'])</x-slot:icon>
    </x-sidebar.link>
    @endif
    @if($can('reputation'))
    <x-sidebar.link
        :href="route('admin.reputation.index')"
        label="Sender Reputation Center"
        :active="request()->routeIs('admin.reputation.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'reputation'])</x-slot:icon>
    </x-sidebar.link>
    @endif
</x-sidebar.section>
@endif

@if($can('sales_ops'))
<x-sidebar.section title="Sales Operations">
    <x-sidebar.link
        :href="route('admin.sales-ops.index')"
        label="Overview"
        :active="request()->routeIs('admin.sales-ops.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif

@if($can('crm'))
<x-sidebar.section title="CRM">
    <x-sidebar.link
        :href="route('admin.crm.index')"
        label="Campaigns"
        :active="request()->routeIs('admin.crm.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif

@if($can('business_research'))
<x-sidebar.section title="Research">
    <x-sidebar.link
        :href="route('admin.business-research.index')"
        label="Business Research"
        :active="request()->routeIs('admin.business-research.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'domain'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif

@if($can('communications'))
<x-sidebar.section title="Communications">
    <x-sidebar.link
        :href="route('admin.communications.index')"
        label="Communications Hub"
        :active="request()->routeIs('admin.communications.*')"
    >
        <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'communications'])</x-slot:icon>
    </x-sidebar.link>
</x-sidebar.section>
@endif

@if($can('user_management'))
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
