@php
    $user = auth()->user();
    $workspaceId = $user?->current_workspace_id;

    $allowedModules = [];
    $can = function (string $module) use (&$allowedModules, $user, $workspaceId): bool {
        if (! $user) {
            return false;
        }

        return $allowedModules[$module] ??= $user->canAccessAdminModule($module, $workspaceId);
    };

    $leadPipelineModules = ['lead_pipeline', 'campaigns'];
    $emailToolkitModules = ['email_lists', 'deliverability', 'content_analyzer', 'reputation'];
    $workspaceAdminModules = ['user_management', 'server_monitoring'];
    $showLeadPipeline = collect($leadPipelineModules)->contains(fn(string $module) => $can($module));
    $showEmailToolkit = collect($emailToolkitModules)->contains(fn(string $module) => $can($module));
    $showWorkspaceAdmin = collect($workspaceAdminModules)->contains(fn(string $module) => $can($module));
    $showDashboard = $user?->canAccessAdminModule('dashboard', $workspaceId);
@endphp

@if ($showDashboard)
    <x-sidebar.section title="Overview">
        <x-sidebar.link :href="route('admin.dashboard')" label="Command Center" icon-name="dashboard"
            :active="request()->routeIs('admin.dashboard*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'dashboard'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($showLeadPipeline)
    <x-sidebar.section title="Lead Pipeline">
        @if ($can('lead_pipeline'))
            <x-sidebar.link :href="route('admin.workflows.create')" label="Import leads" icon-name="pipeline"
                :active="request()->routeIs('admin.workflows.create', 'admin.workflows.show')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('campaigns'))
            <x-sidebar.link :href="route('admin.campaigns.index')" label="Campaigns" icon-name="campaigns"
                :active="request()->routeIs('admin.campaigns.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'campaigns'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif

@if ($showEmailToolkit)
    <x-sidebar.section title="Email Toolkit">
        @if ($can('email_lists'))
            <x-sidebar.link :href="route('admin.lists.index')" label="Bulk Email Verifier" icon-name="verify"
                :active="request()->routeIs('admin.lists.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'verify'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('deliverability'))
            <x-sidebar.link :href="route('admin.deliverability.index')" label="Domain Deliverability Scan" icon-name="domain"
                :active="request()->routeIs('admin.deliverability.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'domain'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('content_analyzer'))
            <x-sidebar.link :href="route('admin.content.index')" label="Outbound Spam Analyzer" icon-name="spam"
                :active="request()->routeIs('admin.content.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'spam'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('reputation'))
            <x-sidebar.link :href="route('admin.reputation.index')" label="Sender Reputation Center" icon-name="reputation"
                :active="request()->routeIs('admin.reputation.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'reputation'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif

@if ($can('sales_ops'))
    <x-sidebar.section title="Sales Operations">
        <x-sidebar.link :href="route('admin.dashboard', ['section' => 'ops'])" label="Team performance" icon-name="sales"
            :active="request()->routeIs('admin.sales-ops.*') && !request()->routeIs('admin.sales-ops.distribution', 'admin.sales-ops.reactivation')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'sales'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($can('crm'))
    <x-sidebar.section title="CRM">
        <x-sidebar.link :href="route('admin.crm.index')" label="Campaigns" icon-name="campaigns"
            :active="request()->routeIs('admin.crm.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'campaigns'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($can('business_research'))
    <x-sidebar.section title="Research">
        <x-sidebar.link :href="route('admin.business-research.index')" label="Business Research" icon-name="research"
            :active="request()->routeIs('admin.business-research.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'research'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($can('communications') || auth()->user()?->canAccessAdminPortal())
    <x-sidebar.section title="Communications">
        <x-sidebar.link :href="route('admin.communications.index')" label="Communications Hub" icon-name="communications"
            :active="request()->routeIs('admin.communications.*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'communications'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($showWorkspaceAdmin)
    <x-sidebar.section title="Workspace Admin">
        @if ($can('user_management'))
            <x-sidebar.link :href="route('admin.workspaces.index')" label="User Management" icon-name="team"
                :active="request()->routeIs('admin.workspaces.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('server_monitoring'))
            <x-sidebar.link :href="route('admin.server.monitoring')" label="Server Monitoring" icon-name="server"
                :active="request()->routeIs('admin.server.monitoring')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'server'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif
