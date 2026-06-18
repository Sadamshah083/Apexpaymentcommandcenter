@extends('layouts.admin')

@section('title', 'Workspace Settings')

@section('content')
<div id="workspace-member-management" class="max-w-4xl mx-auto space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Workspace Management</h1>
            <p class="text-sm text-slate-500 mt-1">Create agent accounts and manage workspace access.</p>
        </div>
    </div>

    <div class="bg-gradient-to-r from-slate-900 to-indigo-950 rounded-2xl shadow-xl p-8 text-white relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
            <div>
                <span class="px-2.5 py-1 rounded bg-indigo-500/20 text-indigo-300 font-bold uppercase text-[10px] tracking-wider">Active Context</span>
                <h2 class="text-2xl font-black mt-2">{{ $activeWorkspace->name }}</h2>
                <p class="text-slate-400 text-sm mt-1">Owner: <strong>{{ $activeWorkspace->admin->name }}</strong> ({{ $activeWorkspace->admin->email }})</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs">
                <span class="px-3 py-1.5 rounded-lg bg-white/10 border border-white/10">Workflows: <strong>{{ $activeWorkspace->workflows->count() }}</strong></span>
                <span class="px-3 py-1.5 rounded-lg bg-white/10 border border-white/10">Team Members: <strong>{{ $activeWorkspace->users->count() }}</strong></span>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
        <div class="space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="text-lg font-bold text-slate-800 mb-4">My Workspaces</h3>
                <div class="space-y-3">
                    @foreach($workspaces as $ws)
                        <div class="p-4 rounded-xl border {{ $ws->id === $activeWorkspace->id ? 'border-indigo-500 bg-indigo-50/20' : 'border-slate-100 bg-slate-50/50' }} flex items-center justify-between">
                            <div>
                                <h4 class="font-bold text-slate-800 text-sm">{{ $ws->name }}</h4>
                                <p class="text-xs text-slate-400 mt-0.5">Owner: {{ $ws->admin->name }}</p>
                            </div>
                            @if($ws->id !== $activeWorkspace->id)
                                <form method="POST" action="{{ route('admin.workspaces.switch', $ws->id) }}">
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

            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="text-lg font-bold text-slate-800 mb-2">Create New Workspace</h3>
                <form method="POST" action="{{ route('admin.workspaces.store') }}" class="space-y-4">
                    @csrf
                    <input type="text" name="name" required placeholder="Workspace name" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                    <button type="submit" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-sm">Create Workspace</button>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6">
                <h3 class="text-lg font-bold text-slate-800 mb-1">Team Members</h3>
                <p class="text-xs text-slate-500 mb-4">Suspend agents to block portal access instantly. Reactivate when ready.</p>

                <div
                    id="workspace-sync-team"
                    class="member-team-list divide-y divide-slate-100 mb-6"
                    data-static-team="1"
                >
                    @foreach($activeWorkspace->users as $member)
                        @include('workflows.partials.member-row', ['member' => $member, 'activeWorkspace' => $activeWorkspace])
                    @endforeach
                </div>

                @if(Auth::user()->isWorkspaceAdmin($activeWorkspace->id))
                    <div class="pt-6 border-t border-slate-100 space-y-4">
                        <h4 class="text-sm font-bold text-slate-800">Add Agent Account</h4>
                        <p class="text-xs text-slate-500">Create a username and password. The agent signs in through the {{ config('app.name') }} agent portal.</p>
                        <form
                            method="POST"
                            action="{{ route('admin.workspaces.members.store', $activeWorkspace->id) }}"
                            class="space-y-4"
                            data-form-loading
                            data-loading-title="Creating account"
                            data-loading-message="Setting up the new agent account…"
                            data-loading-button-text="Creating…"
                        >
                            @csrf
                            <input type="text" name="username" required placeholder="Username" value="{{ old('username') }}" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <input type="password" name="password" required placeholder="Password (min. 6 characters)" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <input type="password" name="password_confirmation" required placeholder="Confirm password" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm">
                            <input type="hidden" name="role" value="marketer">
                            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white font-bold rounded-xl text-sm">Create Agent Account</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@include('workflows.partials.confirm-modal')
@endsection
