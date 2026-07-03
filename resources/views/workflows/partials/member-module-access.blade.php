@php
    use App\Support\MemberModuleAccess;

    $currentUser = auth()->user();
    $selectedModules = $member->getModulePermissions($activeWorkspace->id) ?? [];
    $isRestricted = $member->usesRestrictedModuleAccess($activeWorkspace->id);
    $memberRole = $member->pivot->role ?? 'appointment_setter';
    $showModuleAccess = MemberModuleAccess::isConfigurableRole($memberRole);
@endphp

@if ($showModuleAccess)
    <button type="button"
        class="um-btn um-btn-soft um-btn-sm um-manage-btn um-btn-icon-only"
        data-um-module-access-open
        data-member-id="{{ $member->id }}"
        data-member-name="{{ $member->name }}"
        data-member-role="{{ $memberRole }}"
        aria-label="Module access" title="Module access">
        @include('workflows.partials.um-action-icon', ['name' => 'module-access'])
    </button>

    <div id="um-module-access-source-{{ $member->id }}" class="um-module-access-source" hidden>
        <form method="POST" action="{{ route('admin.workspaces.members.modules', [$activeWorkspace->id, $member->id]) }}"
            data-member-action="modules" data-member-name="{{ $member->name }}"
            data-member-role="{{ $memberRole }}"
            class="member-module-access um-module-panel-inner">
            @csrf
            @method('PATCH')

            @include('workflows.partials.module-access-form-fields', [
                'selectedMode' => $isRestricted ? 'restricted' : 'full',
                'selectedModules' => $selectedModules,
                'workspaceId' => $activeWorkspace->id,
                'memberRole' => $memberRole,
            ])

            <div class="member-confirm-actions um-add-member-actions um-module-access-modal-actions">
                <button type="button" class="member-confirm-cancel" data-um-module-access-dismiss>Cancel</button>
                <button type="submit" class="member-confirm-submit um-btn um-btn-primary">Save module access</button>
            </div>
        </form>
    </div>
@endif
