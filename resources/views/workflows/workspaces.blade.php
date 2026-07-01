@extends('layouts.admin')

@section('title', 'User Management')

@section('content')
@php
    use App\Support\SalesOps;

    $members = $activeWorkspace->users;
    $activeCount = $members->filter(fn ($m) => ($m->pivot->status ?? 'active') === 'active')->count();
    $suspendedCount = $members->filter(fn ($m) => ($m->pivot->status ?? '') === 'suspended')->count();
    $adminPortalUrl = route('admin.login');
    $agentPortalUrl = route('portal.login');
@endphp

<div
    id="workspace-member-management"
    class="um-page"
    data-workspace-id="{{ $activeWorkspace->id }}"
    data-members-base="{{ url('/admin/workspaces/'.$activeWorkspace->id.'/members') }}"
    data-workspace-switch-base="{{ url('/admin/workspaces/switch') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-role-labels='@json(SalesOps::assignableMemberRoles())'
>
    {{-- Page header --}}
    <div class="um-page-header">
        <div>
            <h1 class="um-page-title">User Management</h1>
            <p class="um-page-subtitle">
                Manage workspace members, roles, and access for <strong>{{ $activeWorkspace->name }}</strong>.
                Changes sync live across open admin tabs.
            </p>
        </div>
        <div class="um-live-badge" aria-label="Live sync enabled">
            <span class="um-live-dot"></span>
            Live sync
        </div>
    </div>

    {{-- Stats --}}
    <div class="um-stats-grid">
        <div class="um-stat-card">
            <p class="um-stat-label">Team members</p>
            <p class="um-stat-value" id="workspace-stat-members">{{ $activeWorkspace->users_count }}</p>
        </div>
        <div class="um-stat-card">
            <p class="um-stat-label">Active</p>
            <p class="um-stat-value um-stat-value-success">{{ $activeCount }}</p>
        </div>
        <div class="um-stat-card">
            <p class="um-stat-label">Suspended</p>
            <p class="um-stat-value um-stat-value-muted">{{ $suspendedCount }}</p>
        </div>
        <div class="um-stat-card">
            <p class="um-stat-label">Pipelines</p>
            <p class="um-stat-value" id="workspace-stat-workflows">{{ $activeWorkspace->workflows_count }}</p>
        </div>
    </div>

    <div class="um-layout">
        {{-- Left sidebar: workspace + portals --}}
        <aside class="um-sidebar">
            <section class="um-panel" id="workspace-active-context">
                <div class="um-panel-header">
                    <span class="um-panel-eyebrow">Active workspace</span>
                    <h2 class="um-panel-title" id="workspace-active-name">{{ $activeWorkspace->name }}</h2>
                    <p class="um-panel-meta">
                        Owner <strong id="workspace-active-owner">{{ $activeWorkspace->admin->name }}</strong>
                        <span id="workspace-active-owner-email" class="um-text-muted">({{ $activeWorkspace->admin->email }})</span>
                    </p>
                </div>
            </section>

            <section class="um-panel">
                <div class="um-panel-header um-panel-header-row">
                    <h3 class="um-panel-heading">Workspaces</h3>
                </div>
                <div class="um-panel-body">
                    <div id="workspace-sync-contexts" class="um-workspace-list">
                        @foreach($workspaces as $ws)
                            <div
                                class="um-workspace-card {{ $ws->id === $activeWorkspace->id ? 'um-workspace-card-active' : '' }}"
                                data-workspace-id="{{ $ws->id }}"
                            >
                                <div class="um-workspace-card-body">
                                    <h4 class="um-workspace-name">{{ $ws->name }}</h4>
                                    <p class="um-workspace-meta">Owner: {{ $ws->admin->name }}</p>
                                    <p class="um-workspace-stats">{{ $ws->workflows_count }} pipelines · {{ $ws->users_count }} members</p>
                                </div>
                                @if($ws->id !== $activeWorkspace->id)
                                    <form method="POST" action="{{ route('admin.workspaces.switch', $ws->id) }}" class="workspace-switch-form">
                                        @csrf
                                        <button type="submit" class="um-btn um-btn-ghost um-btn-sm">Switch</button>
                                    </form>
                                @else
                                    <span class="um-badge um-badge-active">Active</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="um-panel um-panel-compact">
                <h3 class="um-panel-heading">Sign-in URLs</h3>
                <div class="um-portal-links">
                    <div class="um-portal-link">
                        <span class="um-portal-link-label">Admin portal</span>
                        <code class="um-portal-link-url">{{ $adminPortalUrl }}</code>
                        <span class="um-portal-link-hint">Super Admin, Admin, Manager</span>
                    </div>
                    <div class="um-portal-link">
                        <span class="um-portal-link-label">Agent portal</span>
                        <code class="um-portal-link-url">{{ $agentPortalUrl }}</code>
                        <span class="um-portal-link-hint">Setters &amp; closers (use <strong>username</strong>, not email)</span>
                    </div>
                </div>
            </section>

            @if(auth()->user()->isSuperAdmin($activeWorkspace->id))
                <section class="um-panel">
                    <h3 class="um-panel-heading">Create workspace</h3>
                    <p class="um-panel-desc">Add an isolated context for another team or client.</p>
                    <form method="POST" action="{{ route('admin.workspaces.store') }}" class="um-form-stack">
                        @csrf
                        <input type="text" name="name" required placeholder="Workspace name" class="um-input">
                        <button type="submit" class="um-btn um-btn-primary um-btn-block">Create workspace</button>
                    </form>
                </section>
            @endif
        </aside>

        {{-- Main: team list + add member --}}
        <div class="um-main">
            <section class="um-panel um-panel-flush">
                <div class="um-panel-toolbar">
                    <div>
                        <h3 class="um-panel-heading">Team members</h3>
                        <p class="um-panel-desc">Suspend, change roles, reset passwords, or limit admin module access.</p>
                    </div>
                    <div class="um-toolbar-actions">
                        <input
                            type="search"
                            id="um-member-search"
                            class="um-input um-search-input"
                            placeholder="Search by name, email, or role…"
                            autocomplete="off"
                            aria-label="Search team members"
                        >
                    </div>
                </div>

                <div
                    id="workspace-sync-team"
                    class="member-team-list um-member-list"
                    data-admin-team="1"
                >
                    @forelse($activeWorkspace->users as $member)
                        @include('workflows.partials.member-row', ['member' => $member, 'activeWorkspace' => $activeWorkspace])
                    @empty
                        <div class="um-empty-state" data-um-empty-members>
                            <p class="um-empty-title">No team members yet</p>
                            <p class="um-empty-desc">Create an agent account below to get started.</p>
                        </div>
                    @endforelse
                </div>

                @if(auth()->user()->canManageWorkspaceMembers($activeWorkspace->id))
                    <div class="um-add-member">
                        <h4 class="um-add-member-title">Add team account</h4>
                        <p class="um-panel-desc mb-4">
                            Admins and managers sign in at the <strong>admin portal</strong>. Setters and closers use the <strong>agent portal</strong> with their username.
                        </p>

                        @if($errors->any())
                            <div class="um-alert um-alert-error" role="alert">
                                <ul class="um-alert-list">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form
                            method="POST"
                            action="{{ route('admin.workspaces.members.store', $activeWorkspace->id) }}"
                            class="um-add-member-form"
                            data-workspace-create-member
                        >
                            @csrf
                            <div class="um-form-grid">
                                <div class="um-field">
                                    <label class="um-label" for="create-username">Username</label>
                                    <input id="create-username" type="text" name="username" required placeholder="e.g. setter_ag_k8z" value="{{ old('username') }}" class="um-input" autocomplete="off">
                                </div>
                                <div class="um-field">
                                    <label class="um-label" for="create-role">Role</label>
                                    <select id="create-role" name="role" class="um-input um-select" data-create-member-role>
                                        @foreach(SalesOps::creatableAgentRoles() as $value => $label)
                                            <option value="{{ $value }}" {{ old('role', 'appointment_setter') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="um-field">
                                    <label class="um-label" for="create-password">Password</label>
                                    <input id="create-password" type="password" name="password" required minlength="6" placeholder="Min. 6 characters" class="um-input" autocomplete="new-password">
                                </div>
                                <div class="um-field">
                                    <label class="um-label" for="create-password-confirm">Confirm password</label>
                                    <input id="create-password-confirm" type="password" name="password_confirmation" required minlength="6" placeholder="Repeat password" class="um-input" autocomplete="new-password">
                                </div>
                            </div>

                            <div class="create-member-modules um-module-panel hidden" data-create-member-modules>
                                @include('workflows.partials.member-module-access-fields', ['prefix' => 'create', 'activeWorkspace' => $activeWorkspace])
                            </div>

                            <button type="submit" class="um-btn um-btn-dark">Create account</button>
                        </form>
                    </div>
                @endif
            </section>
        </div>
    </div>
</div>

@include('workflows.partials.confirm-modal')
@endsection
