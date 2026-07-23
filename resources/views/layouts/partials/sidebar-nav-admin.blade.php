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

    $leadPipelineModules = ['lead_pipeline', 'campaigns', 'maps_scraper'];
    $workspaceAdminModules = ['user_management'];
    $showLeadPipeline = collect($leadPipelineModules)->contains(fn (string $module) => $can($module));
    $showWorkspaceAdmin = collect($workspaceAdminModules)->contains(fn (string $module) => $can($module));
    $showDashboard = $user?->canAccessAdminModule('dashboard', $workspaceId);
    $onAdminDashboard = request()->routeIs('admin.dashboard*');
    $onWorkflows = request()->routeIs('admin.workflows.*');
    $assignedLeadsView = request()->routeIs('admin.assigned-leads');
    $importedLeadsView = $onWorkflows && ! $assignedLeadsView;
@endphp

@if ($showDashboard)
    <x-sidebar.section title="Overview">
        <x-sidebar.link :href="route('admin.dashboard')" label="Dashboard" icon-name="dashboard" query-mode="empty"
            :active="$onAdminDashboard && ! in_array(request('section'), ['imports'], true)">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'dashboard'])</x-slot:icon>
        </x-sidebar.link>
    </x-sidebar.section>
@endif

@if ($showLeadPipeline)
    <x-sidebar.section title="Leads">
        @if ($can('lead_pipeline'))
            <x-sidebar.link :href="route('admin.workflows.index')" label="Imported Leads" icon-name="pipeline"
                query-mode="base"
                :active="$importedLeadsView">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'pipeline'])</x-slot:icon>
            </x-sidebar.link>
            <x-sidebar.link :href="route('admin.assigned-leads')" label="Assigned Leads" icon-name="sales"
                :active="$assignedLeadsView">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'sales'])</x-slot:icon>
            </x-sidebar.link>
        @endif
        @if ($can('maps_scraper'))
            <x-sidebar.link :href="route('admin.maps-scraper.index')" label="Maps Lead Scraper" icon-name="research"
                :active="request()->routeIs('admin.maps-scraper.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'research'])</x-slot:icon>
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

@if ($can('communications') || auth()->user()?->canAccessAdminPortal())
    <x-sidebar.section title="Call Logs">
        <x-sidebar.link :href="route('admin.communications.index')" label="Communications Hub" icon-name="communications"
            :exclude-prefixes="['/admin/communications/call-monitoring', '/admin/communications/monitoring', '/admin/communications/notes', '/admin/communications/agent-status']"
            :active="request()->routeIs('admin.communications.*') && ! request()->routeIs('admin.communications.monitoring*', 'admin.communications.notes', 'admin.communications.agent-status*')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'communications'])</x-slot:icon>
        </x-sidebar.link>
        <x-sidebar.link :href="route('admin.communications.notes')" label="Call Notes" icon-name="notes"
            :active="request()->routeIs('admin.communications.notes')">
            <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'notes'])</x-slot:icon>
        </x-sidebar.link>
        @if (app(\App\Services\Communications\CommunicationsAccessService::class)->canViewCallMonitoring(auth()->user(), 'admin.'))
            <x-sidebar.link
                :href="route('admin.communications.monitoring')"
                label="Call Monitoring"
                icon-name="phone"
                :exclude-prefixes="['/admin/communications/agent-status']"
                :active="request()->routeIs('admin.communications.monitoring*')"
                data-call-monitoring-nav
                data-call-monitoring-poll-url="{{ route('admin.communications.monitoring.live') }}"
                data-call-monitoring-stream-url="{{ route('admin.communications.monitoring.stream') }}">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'phone'])</x-slot:icon>
                <x-slot:badge>
                    <span class="sidebar-live-chip sidebar-live-chip--pink is-empty" title="Active in call" data-call-monitoring-nav-incall hidden>0</span>
                    <span class="sidebar-live-chip sidebar-live-chip--blue is-empty" title="Ringing" data-call-monitoring-nav-waiting hidden>0</span>
                </x-slot:badge>
            </x-sidebar.link>
        @endif
        @if (app(\App\Services\Communications\CommunicationsAccessService::class)->canViewAllCallLogs(auth()->user(), 'admin.'))
            <x-sidebar.link
                :href="route('admin.communications.agent-status')"
                label="All call logs"
                icon-name="notes"
                :active="request()->routeIs('admin.communications.agent-status*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'notes'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif

@if ($showWorkspaceAdmin)
    <x-sidebar.section title="Settings">
        @if ($can('user_management'))
            <x-sidebar.link :href="route('admin.workspaces.index')" label="User Management" icon-name="team"
                :active="request()->routeIs('admin.workspaces.*')">
                <x-slot:icon>@include('layouts.partials.sidebar-icon', ['name' => 'team'])</x-slot:icon>
            </x-sidebar.link>
        @endif
    </x-sidebar.section>
@endif
