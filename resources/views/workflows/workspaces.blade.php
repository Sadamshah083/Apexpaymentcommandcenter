@extends('layouts.admin')

@section('title', 'Collaborators & Contexts')

@section('content')
<div
    id="workspace-member-management"
    class="max-w-5xl mx-auto space-y-8"
    data-workspace-id="{{ $activeWorkspace->id }}"
    data-members-base="{{ url('/admin/workspaces/'.$activeWorkspace->id.'/members') }}"
    data-workspace-switch-base="{{ url('/admin/workspaces/switch') }}"
    data-csrf-token="{{ csrf_token() }}"
    data-role-labels='@json(\App\Support\SalesOps::assignableMemberRoles())'
>
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Collaborators & Contexts</h1>
            <p class="text-sm text-slate-500 mt-1">Switch workspace contexts, manage collaborators, and create agent accounts — updates live across tabs.</p>
        </div>
        <div class="hidden sm:flex items-center gap-2 text-xs text-slate-500">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-100">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                Live sync
            </span>
        </div>
    </div>

    <div class="bg-gradient-to-r from-slate-900 to-indigo-950 rounded-2xl shadow-xl p-8 text-white relative overflow-hidden" id="workspace-active-context">
        <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div>
                <span class="px-2.5 py-1 rounded bg-indigo-500/20 text-indigo-300 font-bold uppercase text-[10px] tracking-wider">Active Context</span>
                <h2 id="workspace-active-name" class="text-2xl font-black mt-2">{{ $activeWorkspace->name }}</h2>
                <p class="text-slate-400 text-sm mt-1">
                    Owner: <strong id="workspace-active-owner">{{ $activeWorkspace->admin->name }}</strong>
                    <span id="workspace-active-owner-email">({{ $activeWorkspace->admin->email }})</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="px-3 py-1.5 rounded-lg bg-white/10 border border-white/10">
                    Pipelines: <strong id="workspace-stat-workflows">{{ $activeWorkspace->workflows_count }}</strong>
                </span>
                <span class="px-3 py-1.5 rounded-lg bg-white/10 border border-white/10">
                    Collaborators: <strong id="workspace-stat-members">{{ $activeWorkspace->users_count }}</strong>
                </span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <div class="space-y-6">
            <div class="app-data-table">
                <div class="app-data-table-header">
                    <h3 class="app-data-table-title">Workspace Contexts</h3>
                </div>
                <div class="p-4">
                    <div id="workspace-sync-contexts" class="space-y-3">
                        @foreach($workspaces as $ws)
                            <div class="workspace-context-card p-4 rounded-xl border {{ $ws->id === $activeWorkspace->id ? 'border-indigo-500 bg-indigo-50/20' : 'border-slate-100 bg-slate-50/50' }} flex items-center justify-between" data-workspace-id="{{ $ws->id }}">
                                <div>
                                    <h4 class="font-bold text-slate-800 text-sm">{{ $ws->name }}</h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Owner: {{ $ws->admin->name }}</p>
                                    <p class="text-[10px] text-slate-400 mt-1">{{ $ws->workflows_count }} pipelines · {{ $ws->users_count }} members</p>
                                </div>
                                @if($ws->id !== $activeWorkspace->id)
                                    <form method="POST" action="{{ route('admin.workspaces.switch', $ws->id) }}" class="workspace-switch-form">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-white hover:bg-slate-50 text-slate-700 font-bold border border-slate-200 rounded-lg text-xs">Switch</button>
                                    </form>
                                @else
                                    <span class="px-2.5 py-1 bg-indigo-100 text-indigo-700 font-bold rounded-lg text-xs">Active</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="app-card app-card-padded">
                <h3 class="text-lg font-bold text-slate-800 mb-2">Create New Context</h3>
                <p class="text-xs text-slate-500 mb-4">Spin up an isolated workspace for another team or client.</p>
                <form method="POST" action="{{ route('admin.workspaces.store') }}" class="space-y-4">
                    @csrf
                    <input type="text" name="name" required placeholder="Workspace name" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-sm">Create Workspace</button>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="app-data-table">
                <div class="app-data-table-header">
                    <div>
                        <h3 class="app-data-table-title">Collaborators</h3>
                        <p class="text-xs text-slate-500 mt-0.5">Suspend, reactivate, or change roles — changes apply instantly.</p>
                    </div>
                </div>
                <div class="p-4">
                    <div
                        id="workspace-sync-team"
                        class="member-team-list divide-y divide-slate-100"
                        data-admin-team="1"
                    >
                        @foreach($activeWorkspace->users as $member)
                            @include('workflows.partials.member-row', ['member' => $member, 'activeWorkspace' => $activeWorkspace])
                        @endforeach
                    </div>
                </div>

                @if(auth()->user()->canManageWorkspaceMembers($activeWorkspace->id))
                    <div class="app-data-table-footer border-t border-slate-100 !bg-white">
                        <h4 class="text-sm font-bold text-slate-800 mb-2">Add Agent Account</h4>
                        <p class="text-xs text-slate-500 mb-4">Create a username and password. Admin and Manager accounts can sign in through the admin portal; agents use the {{ config('app.name') }} agent portal.</p>
                        <form
                            method="POST"
                            action="{{ route('admin.workspaces.members.store', $activeWorkspace->id) }}"
                            class="space-y-4"
                            data-workspace-create-member
                        >
                            @csrf
                            <input type="text" name="username" required placeholder="Username" value="{{ old('username') }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <input type="password" name="password" required placeholder="Password (min. 6 characters)" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <input type="password" name="password_confirmation" required placeholder="Confirm password" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <select name="role" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm" data-create-member-role>
                                @foreach(\App\Support\SalesOps::creatableAgentRoles() as $value => $label)
                                    <option value="{{ $value }}" {{ old('role', 'appointment_setter') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="create-member-modules space-y-3 hidden" data-create-member-modules>
                                @include('workflows.partials.member-module-access-fields', ['prefix' => 'create'])
                            </div>
                            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl text-sm">Create Team Account</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@include('workflows.partials.confirm-modal')
@endsection
