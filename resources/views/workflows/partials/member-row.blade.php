@php
    $status = $member->pivot->status ?? 'active';
    $role = $member->pivot->role ?? 'marketer';
    $isOwner = $activeWorkspace->admin_id === $member->id;
    $canManage = Auth::user()->isWorkspaceAdmin($activeWorkspace->id) && ! $isOwner;
    $nextRole = $role === 'admin' ? 'marketer' : 'admin';
@endphp

<div
    class="member-row py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4 {{ $status === 'suspended' ? 'member-row-suspended' : '' }}"
    data-member-id="{{ $member->id }}"
>
    <div class="member-row-identity min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="font-bold text-slate-800 text-sm">{{ $member->name }}</span>
            @if($isOwner)
                <span class="member-owner-badge">Owner</span>
            @endif
            <span
                class="member-status-badge member-status-{{ $status }}"
                data-member-status
            >{{ $status === 'suspended' ? 'Suspended' : ($status === 'invited' ? 'Invited' : 'Active') }}</span>
        </div>
        <div class="text-xs text-slate-400 mt-1 truncate">{{ $member->email }}</div>
        <div class="text-xs text-slate-500 mt-0.5" data-member-role>
            {{ $role === 'marketer' ? 'Agent' : 'Administrator' }}
        </div>
    </div>

    @if($canManage)
        <div class="member-row-actions flex flex-wrap items-center gap-2">
            <form
                method="POST"
                action="{{ route('admin.workspaces.members.role', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="role"
                data-member-name="{{ $member->name }}"
                data-next-role="{{ $nextRole }}"
            >
                @csrf
                @method('PATCH')
                <input type="hidden" name="role" value="{{ $nextRole }}">
                <button type="submit" class="member-action-btn member-action-btn-role">
                    {{ $role === 'admin' ? 'Make agent' : 'Make admin' }}
                </button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.workspaces.members.suspend', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="suspend"
                data-member-name="{{ $member->name }}"
                @if($status === 'suspended') hidden @endif
            >
                @csrf
                <button type="submit" class="member-action-btn member-action-btn-suspend">Suspend</button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.workspaces.members.reactivate', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="reactivate"
                data-member-name="{{ $member->name }}"
                @if($status !== 'suspended') hidden @endif
            >
                @csrf
                <button type="submit" class="member-action-btn member-action-btn-reactivate">Reactivate</button>
            </form>

            <form
                method="POST"
                action="{{ route('admin.workspaces.members.destroy', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="remove"
                data-member-name="{{ $member->name }}"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="member-action-btn member-action-btn-remove">Remove</button>
            </form>
        </div>
    @endif
</div>
