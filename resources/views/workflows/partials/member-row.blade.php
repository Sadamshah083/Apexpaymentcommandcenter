@php
    use App\Support\SalesOps;

    $status = $member->pivot->status ?? 'active';
    $role = $member->pivot->role ?? 'sdr';
    $isOwner = $activeWorkspace->admin_id === $member->id;
    $canManage = Auth::user()->isSuperAdmin($activeWorkspace->id) && ! $isOwner;
    $roleLabel = SalesOps::roleLabel($role);
    $assignableRoles = SalesOps::assignableMemberRoles();
@endphp

<div
    class="member-row py-4 flex flex-col sm:flex-row sm:items-start justify-between gap-4 {{ $status === 'suspended' ? 'member-row-suspended' : '' }}"
    data-member-id="{{ $member->id }}"
    data-member-name="{{ $member->name }}"
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
        <div class="text-xs text-slate-500 mt-0.5" data-member-role>{{ $roleLabel }}</div>
    </div>

    @if($canManage)
        <div class="member-row-actions flex flex-col gap-3 w-full sm:w-auto sm:min-w-[280px]">
            <form
                method="POST"
                action="{{ route('admin.workspaces.members.role', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="role"
                data-member-name="{{ $member->name }}"
                class="flex items-center gap-2"
            >
                @csrf
                @method('PATCH')
                <label class="text-xs text-slate-500 shrink-0">Role</label>
                <select
                    name="role"
                    class="member-role-select flex-1 px-2 py-1.5 bg-white border border-slate-200 rounded-lg text-xs"
                    data-member-role-select
                >
                    @foreach($assignableRoles as $value => $label)
                        <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="member-action-btn member-action-btn-role text-xs">Save</button>
            </form>

            <details class="member-reset-password text-xs">
                <summary class="cursor-pointer text-indigo-600 font-medium">Reset password</summary>
                <form
                    method="POST"
                    action="{{ route('admin.workspaces.members.reset-password', [$activeWorkspace->id, $member->id]) }}"
                    data-member-action="reset-password"
                    data-member-name="{{ $member->name }}"
                    class="mt-2 space-y-2"
                >
                    @csrf
                    <input type="password" name="password" required minlength="6" placeholder="New password" class="w-full px-2 py-1.5 border border-slate-200 rounded-lg">
                    <input type="password" name="password_confirmation" required minlength="6" placeholder="Confirm password" class="w-full px-2 py-1.5 border border-slate-200 rounded-lg">
                    <button type="submit" class="member-action-btn member-action-btn-role w-full">Update password</button>
                </form>
            </details>

            <div class="flex flex-wrap items-center gap-2">
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
        </div>
    @endif
</div>
