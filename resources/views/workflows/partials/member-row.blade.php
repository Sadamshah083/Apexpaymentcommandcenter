@php
    use App\Support\MemberModuleAccess;
    use App\Support\SalesOps;

    $status = $member->pivot->status ?? 'active';
    $role = $member->pivot->role ?? 'appointment_setter';
    $isSuperAdminMember = $role === 'super_admin';
    $canManageMembership = Auth::user()->canManageWorkspaceMembers($activeWorkspace->id) && ! $isSuperAdminMember;
    $canAssignModules = Auth::user()->canAssignModulePermissions($activeWorkspace->id) && ! $isSuperAdminMember;
    $roleLabel = SalesOps::roleLabel($role);
    $assignableRoles = SalesOps::assignableMemberRoles();
    $modulePermissions = $member->getModulePermissions($activeWorkspace->id);
    $moduleSummary = MemberModuleAccess::accessSummaryLabel(
        $role,
        $modulePermissions,
        $member->usesRestrictedModuleAccess($activeWorkspace->id),
    );
    $portalType = SalesOps::isAdminPortalRole($role) ? 'admin' : (SalesOps::isPortalRole($role) ? 'agent' : 'other');
    $initials = strtoupper(substr($member->name, 0, 2));
@endphp

<tr
    class="member-row um-member-row {{ $status === 'suspended' ? 'member-row-suspended um-member-row-suspended' : '' }}"
    data-member-id="{{ $member->id }}" data-member-name="{{ $member->name }}"
    data-member-search="{{ strtolower($member->name . ' ' . $member->email . ' ' . $roleLabel) }}">
    <td class="col-member">
        <div class="um-table-member">
            <span class="um-member-avatar" aria-hidden="true">{{ $initials }}</span>
            <div class="um-table-member-meta">
                <div class="um-member-title-row">
                    <span class="um-member-name">{{ $member->name }}</span>
                    @if ($isSuperAdminMember)
                        <span class="member-owner-badge um-badge um-badge-owner">Super Admin</span>
                    @elseif ($activeWorkspace->admin_id === $member->id)
                        <span class="member-owner-badge um-badge um-badge-owner">Owner</span>
                    @endif
                </div>
                <span class="um-member-email um-text-muted">{{ $member->email }}</span>
            </div>
        </div>
    </td>
    <td class="col-role">
        @if ($canManageMembership)
            <form method="POST"
                action="{{ route('admin.workspaces.members.role', [$activeWorkspace->id, $member->id]) }}"
                data-member-action="role" data-member-name="{{ $member->name }}" class="um-role-cell-form">
                @csrf
                @method('PATCH')
                @include('workflows.partials.role-select-dropdown', [
                    'assignableRoles' => $assignableRoles,
                    'selectedRole' => $role,
                    'memberName' => $member->name,
                ])
                <button type="submit"
                    class="um-btn um-btn-primary um-btn-sm um-btn-icon-only member-action-btn member-action-btn-role um-manage-btn"
                    aria-label="Save role" title="Save role">
                    @include('workflows.partials.um-action-icon', ['name' => 'save'])
                </button>
            </form>
        @else
            <span class="um-cell-value um-role-readonly" data-member-role>{{ $roleLabel }}</span>
        @endif
    </td>
    <td class="col-status">
        <span class="member-status-badge member-status-{{ $status }} um-badge um-badge-status-{{ $status }}"
            data-member-status>{{ $status === 'suspended' ? 'Suspended' : ($status === 'invited' ? 'Invited' : 'Active') }}</span>
    </td>
    <td class="col-portal">
        @if ($portalType === 'admin')
            <span class="um-badge um-badge-portal-admin">Admin</span>
        @elseif($portalType === 'agent')
            <span class="um-badge um-badge-portal-agent">Agent</span>
        @else
            <span class="um-text-muted">—</span>
        @endif
    </td>
    <td class="col-access">
        <span class="um-cell-value um-text-muted" data-member-module-summary>{{ $moduleSummary }}</span>
    </td>
    <td class="col-manage">
        @if ($canManageMembership || $canAssignModules)
            <div class="um-manage-actions">
                @if ($canManageMembership)
                    <button type="button"
                        class="um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only"
                        data-um-edit-member-open
                        data-edit-url="{{ route('admin.workspaces.members.update', [$activeWorkspace->id, $member->id]) }}"
                        data-member-name="{{ $member->name }}"
                        data-member-email="{{ $member->email }}"
                        aria-label="Edit account" title="Edit account">
                        @include('workflows.partials.um-action-icon', ['name' => 'edit'])
                    </button>

                    <form method="POST"
                        action="{{ route('admin.workspaces.members.suspend', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="suspend" data-member-name="{{ $member->name }}"
                        @if ($status === 'suspended') hidden @endif>
                        @csrf
                        <button type="submit"
                            class="member-action-btn member-action-btn-suspend um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only um-btn-warning-text"
                            aria-label="Suspend" title="Suspend">
                            @include('workflows.partials.um-action-icon', ['name' => 'suspend'])
                        </button>
                    </form>

                    <form method="POST"
                        action="{{ route('admin.workspaces.members.reactivate', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="reactivate" data-member-name="{{ $member->name }}"
                        @if ($status !== 'suspended') hidden @endif>
                        @csrf
                        <button type="submit"
                            class="member-action-btn member-action-btn-reactivate um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only um-btn-success-text"
                            aria-label="Activate" title="Activate">
                            @include('workflows.partials.um-action-icon', ['name' => 'activate'])
                        </button>
                    </form>

                    <form method="POST"
                        action="{{ route('admin.workspaces.members.destroy', [$activeWorkspace->id, $member->id]) }}"
                        data-member-action="remove" data-member-name="{{ $member->name }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="member-action-btn member-action-btn-remove um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only um-btn-danger-text"
                            aria-label="Delete" title="Delete">
                            @include('workflows.partials.um-action-icon', ['name' => 'delete'])
                        </button>
                    </form>
                @endif

                @if ($canAssignModules)
                    @include('workflows.partials.member-module-access', [
                        'member' => $member,
                        'activeWorkspace' => $activeWorkspace,
                    ])
                @endif
            </div>
        @else
            <span class="um-text-muted">—</span>
        @endif
    </td>
</tr>
