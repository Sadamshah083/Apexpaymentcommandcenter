@php
    $status = $member->pivot->status ?? 'active';
    $isOwner = $activeWorkspace->admin_id === $member->id;
    $canManage = Auth::user()->isWorkspaceAdmin($activeWorkspace->id) && ! $isOwner;
    $roleLabel = $member->pivot->role === 'marketer' ? 'Agent' : 'Admin';
    $nextRole = $member->pivot->role === 'admin' ? 'marketer' : 'admin';
@endphp

<div class="member-row py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 {{ $status === 'suspended' ? 'member-row-suspended' : '' }}"
     data-member-id="{{ $member->id }}"
     data-member-name="{{ $member->name }}">
    <div class="flex items-start gap-3 min-w-0">
        <div class="member-avatar shrink-0" aria-hidden="true">{{ strtoupper(substr($member->name, 0, 1)) }}</div>
        <div class="min-w-0">
            <div class="font-bold text-slate-800 text-sm truncate">{{ $member->name }}</div>
            <div class="text-xs text-slate-400 mt-0.5">{{ $roleLabel }}</div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 sm:justify-end">
        <span data-member-status
              class="member-status-badge member-status-{{ $status }}">
            @if($status === 'suspended')
                Suspended
            @elseif($status === 'invited')
                Invited
            @else
                Active
            @endif
        </span>

        @if($canManage)
            <form method="POST"
                  action="{{ route('admin.workspaces.members.role', [$activeWorkspace->id, $member->id]) }}"
                  class="member-action-form"
                  data-member-action="role"
                  data-member-name="{{ $member->name }}"
                  data-next-role="{{ $nextRole }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="role" value="{{ $nextRole }}">
                <button type="submit" class="member-action-btn member-action-btn-role">
                    {{ $member->pivot->role === 'admin' ? 'Demote to agent' : 'Promote to admin' }}
                </button>
            </form>

            @if($status === 'suspended')
                <form method="POST"
                      action="{{ route('admin.workspaces.members.reactivate', [$activeWorkspace->id, $member->id]) }}"
                      class="member-action-form"
                      data-member-action="reactivate"
                      data-member-name="{{ $member->name }}">
                    @csrf
                    <button type="submit" class="member-action-btn member-action-btn-reactivate">Reactivate</button>
                </form>
            @else
                <form method="POST"
                      action="{{ route('admin.workspaces.members.suspend', [$activeWorkspace->id, $member->id]) }}"
                      class="member-action-form"
                      data-member-action="suspend"
                      data-member-name="{{ $member->name }}">
                    @csrf
                    <button type="submit" class="member-action-btn member-action-btn-suspend">Suspend</button>
                </form>
            @endif

            <form method="POST"
                  action="{{ route('admin.workspaces.members.destroy', [$activeWorkspace->id, $member->id]) }}"
                  class="member-action-form"
                  data-member-action="remove"
                  data-member-name="{{ $member->name }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="member-action-btn member-action-btn-remove">Remove</button>
            </form>
        @elseif($isOwner)
            <span class="text-[10px] font-bold uppercase tracking-wide text-indigo-600">Owner</span>
        @endif
    </div>
</div>
