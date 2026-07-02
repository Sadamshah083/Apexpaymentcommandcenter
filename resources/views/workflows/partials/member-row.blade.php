@php
    use App\Support\SalesOps;

    $status = $member->pivot->status ?? 'active';
    $role = $member->pivot->role ?? 'appointment_setter';
    $isOwner = $activeWorkspace->admin_id === $member->id;
    $canManageMembership = Auth::user()->canManageWorkspaceMembers($activeWorkspace->id) && !$isOwner;
    $canAssignModules = Auth::user()->canAssignModulePermissions($activeWorkspace->id) && !$isOwner;
    $roleLabel = SalesOps::roleLabel($role);
    $assignableRoles = SalesOps::assignableMemberRoles();
    $modulePermissions = $member->getModulePermissions($activeWorkspace->id);
    $moduleSummary = $member->usesRestrictedModuleAccess($activeWorkspace->id)
        ? count($modulePermissions) . ' module(s)'
        : (SalesOps::isAdminPortalRole($role) && $role !== 'super_admin'
            ? 'Full admin access'
            : null);
    $portalType = SalesOps::isAdminPortalRole($role) ? 'admin' : (SalesOps::isPortalRole($role) ? 'agent' : 'other');
    $initials = strtoupper(substr($member->name, 0, 2));
@endphp

<article
    class="member-row um-member-card {{ $status === 'suspended' ? 'member-row-suspended um-member-card-suspended' : '' }}"
    data-member-id="{{ $member->id }}" data-member-name="{{ $member->name }}"
    data-member-search="{{ strtolower($member->name . ' ' . $member->email . ' ' . $roleLabel) }}">
    <div class="um-member-card-main">
        <div class="um-member-avatar" aria-hidden="true">{{ $initials }}</div>

        <div class="member-row-identity um-member-info">
            <div class="um-member-title-row">
                <h4 class="um-member-name">{{ $member->name }}</h4>
                @if ($isOwner)
                    <span class="member-owner-badge um-badge um-badge-owner">Owner</span>
                @endif
                <span
                    class="member-status-badge member-status-{{ $status }} um-badge um-badge-status-{{ $status }}"
                    data-member-status>{{ $status === 'suspended' ? 'Suspended' : ($status === 'invited' ? 'Invited' : 'Active') }}</span>
                @if ($portalType === 'admin')
                    <span class="um-badge um-badge-portal-admin">Admin portal</span>
                @elseif($portalType === 'agent')
                    <span class="um-badge um-badge-portal-agent">Agent portal</span>
                @endif
            </div>
            <p class="um-member-email">{{ $member->email }}</p>
            <p class="um-member-role" data-member-role>{{ $roleLabel }}</p>
            @if ($moduleSummary)
                <p class="um-member-modules" data-member-module-summary>{{ $moduleSummary }}</p>
            @else
                <p class="um-member-modules hidden" data-member-module-summary></p>
            @endif
        </div>
    </div>

    @if ($canManageMembership || $canAssignModules)
        <div class="member-row-actions um-member-actions">
            @if ($canManageMembership)
                <div class="um-action-group">
                    <form method="POST"
                        action="{{ route('admin.workspaces.members.role', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="role" data-member-name="{{ $member->name }}" class="um-role-form">
                        @csrf
                        @method('PATCH')
                        <label class="um-label um-label-inline">Role</label>
                        <select name="role" class="um-input um-select um-select-sm member-role-select"
                            data-member-role-select>
                            @foreach ($assignableRoles as $value => $label)
                                <option value="{{ $value }}" @selected($role === $value)>{{ $label }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit"
                            class="um-btn um-btn-ghost um-btn-sm member-action-btn member-action-btn-role">Save</button>
                    </form>
                </div>

                <details class="um-details member-reset-password">
                    <summary>Reset password</summary>
                    <form method="POST"
                        action="{{ route('admin.workspaces.members.reset-password', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="reset-password" data-member-name="{{ $member->name }}"
                        class="um-form-stack um-form-stack-tight">
                        @csrf
                        <input type="password" name="password" required minlength="6" placeholder="New password"
                            class="um-input um-input-sm" autocomplete="new-password">
                        <input type="password" name="password_confirmation" required minlength="6"
                            placeholder="Confirm password" class="um-input um-input-sm" autocomplete="new-password">
                        <button type="submit"
                            class="um-btn um-btn-ghost um-btn-sm um-btn-block member-action-btn member-action-btn-role">Update
                            password</button>
                    </form>
                </details>

                <div class="um-action-buttons">
                    <form method="POST"
                        action="{{ route('admin.workspaces.members.suspend', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="suspend" data-member-name="{{ $member->name }}"
                        @if ($status === 'suspended') hidden @endif>
                        @csrf
                        <button type="submit"
                            class="member-action-btn member-action-btn-suspend um-btn um-btn-warning um-btn-sm">Suspend</button>
                    </form>

                    <form method="POST"
                        action="{{ route('admin.workspaces.members.reactivate', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="reactivate" data-member-name="{{ $member->name }}"
                        @if ($status !== 'suspended') hidden @endif>
                        @csrf
                        <button type="submit"
                            class="member-action-btn member-action-btn-reactivate um-btn um-btn-success um-btn-sm">Reactivate</button>
                    </form>

                    <form method="POST"
                        action="{{ route('admin.workspaces.members.destroy', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="remove" data-member-name="{{ $member->name }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="member-action-btn member-action-btn-remove um-btn um-btn-danger um-btn-sm">Remove</button>
                    </form>
                </div>
            @endif

            @if ($canAssignModules)
                @include('workflows.partials.member-module-access', [
                    'member' => $member,
                    'activeWorkspace' => $activeWorkspace,
                ])
            @endif
        </div>
    @endif
</article>
