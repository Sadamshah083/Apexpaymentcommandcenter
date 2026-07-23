@php
    use App\Support\MemberModuleAccess;
    use App\Support\SalesOps;

    $status = $member->pivot->status ?? 'active';
    $role = $member->pivot->role ?? 'appointment_setter';
    $isSuperAdminMember = $role === 'super_admin';
    $isWorkspaceAdminMember = $role === 'admin' || (int) $activeWorkspace->admin_id === (int) $member->id;
    $viewerCanManage = Auth::user()->canManageWorkspaceMembers($activeWorkspace->id);
    $canManageMembership = $viewerCanManage && ! $isSuperAdminMember;
    $canEditAccount = $viewerCanManage && (! $isSuperAdminMember || Auth::user()->isPlatformSuperAdmin());
    $canAssignModules = Auth::user()->canAssignModulePermissions($activeWorkspace->id) && ! $isSuperAdminMember;
    $canDeleteMember = $canManageMembership && ! $isWorkspaceAdminMember;
    $roleLabel = SalesOps::roleLabel($role);
    $modulePermissions = $member->getModulePermissions($activeWorkspace->id);
    $moduleSummary = MemberModuleAccess::accessSummaryLabel(
        $role,
        $modulePermissions,
        $member->usesRestrictedModuleAccess($activeWorkspace->id),
    );
    $portalType = SalesOps::isAdminPortalRole($role) ? 'admin' : (SalesOps::isPortalRole($role) ? 'agent' : 'other');
    $initials = strtoupper(substr($member->name, 0, 2));
    $isTeamLead = SalesOps::isTeamLeadRole($role);
    $isAgent = SalesOps::isAgentRole($role);
    $expectedLeadRole = SalesOps::teamLeadRoleFor($role);
    $teamLeadOptions = collect();
    if ($expectedLeadRole === 'appointment_setter_team_lead') {
        $teamLeadOptions = collect($setterTeamLeads ?? []);
    } elseif ($expectedLeadRole === 'closers_team_lead') {
        $teamLeadOptions = collect($closerTeamLeads ?? []);
    }
    $selectedTeamLeadId = (int) ($member->pivot->team_lead_user_id ?? 0);
    $campaigns = collect($campaigns ?? []);
    $campaignNames = collect($campaignNames ?? []);
    $teamLeadCampaignIds = collect($teamLeadCampaignIds ?? []);
    $selectedCampaignId = (int) ($member->pivot->campaign_id ?? 0);
    if ($isAgent && $selectedTeamLeadId > 0) {
        $inherited = (int) ($teamLeadCampaignIds->get($selectedTeamLeadId) ?? 0);
        if ($inherited > 0) {
            $selectedCampaignId = $inherited;
        }
    }
    $selectedCampaignLabel = $selectedCampaignId > 0 ? ($campaignNames->get($selectedCampaignId) ?: null) : null;
    $teamLeadNames = collect($teamLeadNames ?? []);
    $selectedTeamLeadLabel = $selectedTeamLeadId > 0
        ? ($teamLeadNames->get($selectedTeamLeadId) ?: $teamLeadOptions->firstWhere('id', $selectedTeamLeadId)?->name)
        : null;
    $teamMemberCount = $isTeamLead
        ? (int) (collect($teamMemberCounts ?? [])->get((int) $member->id) ?? 0)
        : 0;
@endphp

<tr
    class="member-row um-member-row {{ $status === 'suspended' ? 'member-row-suspended um-member-row-suspended' : '' }}"
    data-member-id="{{ $member->id }}" data-member-name="{{ $member->name }}"
    data-member-search="{{ strtolower($member->name . ' ' . $member->email . ' ' . $roleLabel . ' ' . ($selectedTeamLeadLabel ?? '') . ' ' . ($selectedCampaignLabel ?? '')) }}">
    <td class="col-member">
        <div class="um-table-member">
            <span class="um-member-avatar" aria-hidden="true">{{ $initials }}</span>
            <span class="um-member-name">
                {{ $member->name }}
                @if ($isSuperAdminMember)
                    <span class="member-owner-badge um-badge um-badge-owner">Super Admin</span>
                @elseif ($activeWorkspace->admin_id === $member->id)
                    <span class="member-owner-badge um-badge um-badge-owner">Owner</span>
                @endif
            </span>
            <span class="um-member-email um-text-muted">{{ $member->email }}</span>
            @if ($isAgent && $selectedTeamLeadLabel)
                <span class="um-member-team-line" data-member-under-lead>Under {{ $selectedTeamLeadLabel }}</span>
            @elseif ($isTeamLead)
                <span class="um-member-team-line" data-member-team-count>
                    {{ $teamMemberCount }} {{ $teamMemberCount === 1 ? 'team member' : 'team members' }}
                </span>
            @endif
        </div>
    </td>
    <td class="col-role">
        <span class="um-cell-value um-role-readonly" data-member-role>{{ $roleLabel }}</span>
    </td>
    <td class="col-campaign">
        @if ($isTeamLead)
            @if ($selectedCampaignLabel)
                <span class="um-badge um-badge-campaign" data-member-campaign>{{ $selectedCampaignLabel }}</span>
            @else
                <span class="um-text-muted" data-member-campaign>Unassigned</span>
            @endif
        @elseif ($isAgent)
            @if ($selectedCampaignLabel)
                <span class="um-badge um-badge-campaign" data-member-campaign>{{ $selectedCampaignLabel }}</span>
                <span class="um-text-muted text-xs block">From team lead</span>
            @else
                <span class="um-text-muted" data-member-campaign>
                    {{ $selectedTeamLeadId ? 'Team lead has no campaign' : 'Unassigned' }}
                </span>
            @endif
        @else
            <span class="um-text-muted" data-member-campaign>—</span>
        @endif
    </td>
    <td class="col-team">
        @if ($isTeamLead)
            <span class="um-badge um-badge-portal-agent" data-member-team-lead>Own team</span>
        @elseif ($isAgent)
            @if ($selectedTeamLeadLabel)
                <span class="um-cell-value" data-member-team-lead>{{ $selectedTeamLeadLabel }}</span>
            @else
                <span class="um-text-muted" data-member-team-lead>Unassigned</span>
            @endif
        @else
            <span class="um-text-muted" data-member-team-lead>—</span>
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
    @if (! empty($showPasswordHint))
        <td class="col-password">
            <code class="um-password-hint" data-member-password-hint title="Admin-only login password">{{ filled($member->password_hint) ? $member->password_hint : '—' }}</code>
        </td>
    @endif
    <td class="col-manage">
        @if ($canEditAccount || $canManageMembership || $canAssignModules)
            <div class="um-manage-actions">
                @if ($canEditAccount)
                    <button type="button"
                        class="um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only"
                        data-um-edit-member-open
                        data-edit-url="{{ route('admin.workspaces.members.update', [$activeWorkspace->id, $member->id]) }}"
                        data-member-name="{{ $member->name }}"
                        data-member-email="{{ $member->email }}"
                        data-member-role="{{ $role }}"
                        data-member-team-lead-id="{{ $selectedTeamLeadId }}"
                        data-member-campaign-id="{{ (int) ($member->pivot->campaign_id ?? 0) }}"
                        data-member-password-hint="{{ $member->password_hint ?? '' }}"
                        aria-label="Edit account" title="Edit account">
                        @include('workflows.partials.um-action-icon', ['name' => 'edit'])
                    </button>

                    <button type="button"
                        class="um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only"
                        data-um-reset-password-open
                        data-reset-url="{{ route('admin.workspaces.members.reset-password', [$activeWorkspace->id, $member->id]) }}"
                        data-member-name="{{ $member->name }}"
                        data-member-password-hint="{{ $member->password_hint ?? '' }}"
                        aria-label="Change password" title="Change password">
                        @include('workflows.partials.um-action-icon', ['name' => 'settings'])
                    </button>
                @endif

                @if ($canManageMembership)
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

                    @if ($canDeleteMember)
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
