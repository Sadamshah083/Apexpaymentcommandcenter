@extends('layouts.admin')

@section('title', 'User Management')

@section('content')
    @php
        use App\Support\SalesOps;

        $adminPortalUrl = route('admin.login');
        $agentPortalUrl = route('portal.login');
    @endphp

    <div id="workspace-member-management" class="app-page um-page" data-workspace-id="{{ $activeWorkspace->id }}"
        data-members-base="{{ url('/admin/workspaces/' . $activeWorkspace->id . '/members') }}"
        data-workspace-switch-base="{{ url('/admin/workspaces/switch') }}" data-csrf-token="{{ csrf_token() }}"
        data-role-labels='@json(SalesOps::assignableMemberRoles())'>
        {{-- Page header --}}
        <div class="um-page-header app-page-header">
            <div>
                <h1 class="um-page-title app-page-title">User Management</h1>
                <p class="um-page-subtitle app-page-subtitle">
                    Manage workspace members, roles, and access for
                    <span class="um-text-emphasis" id="workspace-active-name">{{ $activeWorkspace->name }}</span>.
                    Changes sync live across open admin tabs.
                </p>
            </div>
            <div class="um-page-header-actions">
                <button type="button" class="um-btn um-btn-ghost um-btn-sm" data-um-portal-info-open>
                    Portal links
                </button>
                <div class="um-live-badge" aria-label="Live sync enabled">
                    <span class="um-live-dot"></span>
                    Live sync
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="um-stats-grid app-stat-grid">
            <div class="um-stat-card app-card app-card-padded">
                <p class="um-stat-label app-kpi-label">Team members</p>
                <p class="um-stat-value app-kpi-value" id="workspace-stat-members">{{ $activeWorkspace->users_count }}</p>
            </div>
            <div class="um-stat-card app-card app-card-padded">
                <p class="um-stat-label app-kpi-label">Active</p>
                <p class="um-stat-value app-kpi-value">{{ $activeMemberCount }}</p>
            </div>
            <div class="um-stat-card app-card app-card-padded">
                <p class="um-stat-label app-kpi-label">Suspended</p>
                <p class="um-stat-value app-kpi-value">{{ $suspendedMemberCount }}</p>
            </div>
            <div class="um-stat-card app-card app-card-padded">
                <p class="um-stat-label app-kpi-label">Pipelines</p>
                <p class="um-stat-value app-kpi-value" id="workspace-stat-workflows">{{ $activeWorkspace->workflows_count }}</p>
            </div>
        </div>

        {{-- Workspaces table --}}
        <section class="um-panel um-panel-flush um-section">
            <div class="um-panel-toolbar">
                <div>
                    <h3 class="um-panel-heading">Workspaces</h3>
                    <p class="um-panel-desc">
                        Owner
                        <span id="workspace-active-owner" class="um-text-emphasis">{{ $activeWorkspace->admin->name }}</span>
                        <span id="workspace-active-owner-email" class="um-text-muted">({{ $activeWorkspace->admin->email }})</span>
                    </p>
                </div>
                <div class="um-toolbar-actions">
                    @if (auth()->user()->isSuperAdmin($activeWorkspace->id))
                        <button type="button" class="um-btn um-btn-primary um-btn-sm shrink-0" data-um-create-workspace-open>
                            Create workspace
                        </button>
                    @endif
                </div>
            </div>

            <div class="app-data-table um-workspaces-table-wrap">
                <div class="app-table-wrap">
                    <table class="um-workspaces-table">
                        <thead>
                            <tr>
                                <th class="col-name">Workspace</th>
                                <th class="col-owner">Owner</th>
                                <th class="col-pipelines">Pipelines</th>
                                <th class="col-members">Members</th>
                                <th class="col-status text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody id="workspace-sync-contexts">
                            @foreach ($workspaces as $ws)
                                @include('workflows.partials.workspace-row', [
                                    'ws' => $ws,
                                    'activeWorkspace' => $activeWorkspace,
                                ])
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Team members table --}}
        <section class="um-panel um-panel-flush um-section">
            <div class="um-panel-toolbar">
                <div>
                    <h3 class="um-panel-heading">Team members</h3>
                    <p class="um-panel-desc">Suspend, change roles, reset passwords, or limit admin module access.</p>
                </div>
                <div class="um-toolbar-actions">
                    <input type="search" id="um-member-search" class="um-input um-search-input"
                        placeholder="Search by name, email, or role…" autocomplete="off"
                        aria-label="Search team members">
                    @if (auth()->user()->canManageWorkspaceMembers($activeWorkspace->id))
                        <button type="button" class="um-btn um-btn-primary um-btn-sm shrink-0" data-um-add-member-open>
                            Add account
                        </button>
                    @endif
                </div>
            </div>

            <x-data-table :paginator="$members" min-width="1080px" class="um-members-table-wrap">
                <table class="um-members-table">
                    <thead>
                        <tr>
                            <th class="col-member">Member</th>
                            <th class="col-role">Role</th>
                            <th class="col-status">Status</th>
                            <th class="col-portal">Portal</th>
                            <th class="col-access">Access</th>
                            <th class="col-manage text-right">Manage</th>
                        </tr>
                    </thead>
                    <tbody id="workspace-sync-team" class="member-team-list" data-admin-team="1">
                        @forelse($members as $member)
                            @include('workflows.partials.member-row', [
                                'member' => $member,
                                'activeWorkspace' => $activeWorkspace,
                            ])
                        @empty
                            <tr data-um-empty-members>
                                <td colspan="6">
                                    <div class="um-empty-state">
                                        <p class="um-empty-title">No team members yet</p>
                                        <p class="um-empty-desc">Create an agent account to get started.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-data-table>
        </section>
    </div>

    @if (auth()->user()->canManageWorkspaceMembers($activeWorkspace->id))
        @include('workflows.partials.add-member-modal', ['activeWorkspace' => $activeWorkspace])
    @endif

    @if (auth()->user()->isSuperAdmin($activeWorkspace->id))
        @include('workflows.partials.create-workspace-modal')
    @endif

    @include('workflows.partials.portal-info-modal', [
        'adminPortalUrl' => $adminPortalUrl,
        'agentPortalUrl' => $agentPortalUrl,
    ])

    @include('workflows.partials.confirm-modal')
    @include('workflows.partials.edit-member-modal')
    @include('workflows.partials.module-access-modal')
@endsection
