@php
    use App\Support\AdminModules;
    use App\Support\SalesOps;

    $moduleGroups = AdminModules::groupedForUi();
    $currentUser = auth()->user();
    $canGrantUserManagement = $currentUser?->isSuperAdmin($activeWorkspace->id);
    $selectedModules = $member->getModulePermissions($activeWorkspace->id) ?? [];
    $isRestricted = $member->usesRestrictedModuleAccess($activeWorkspace->id);
    $memberRole = $member->pivot->role ?? 'appointment_setter';
    $showModuleAccess = SalesOps::isAdminPortalRole($memberRole) && $memberRole !== 'super_admin';
@endphp

@if($showModuleAccess)
    <details class="um-details um-module-details member-module-access">
        <summary>Module access</summary>
        <form
            method="POST"
            action="{{ route('admin.workspaces.members.modules', [$activeWorkspace->id, $member->id]) }}"
            data-member-action="modules"
            data-member-name="{{ $member->name }}"
            class="member-module-access um-module-panel-inner"
        >
            @csrf
            @method('PATCH')

            <div class="um-access-mode">
                <label class="um-radio-pill">
                    <input
                        type="radio"
                        name="access_mode"
                        value="full"
                        @checked(! $isRestricted)
                        class="member-access-mode"
                    >
                    <span>Full access</span>
                </label>
                <label class="um-radio-pill">
                    <input
                        type="radio"
                        name="access_mode"
                        value="restricted"
                        @checked($isRestricted)
                        class="member-access-mode"
                    >
                    <span>Selected modules</span>
                </label>
            </div>

            <div class="member-module-grid um-module-grid {{ $isRestricted ? '' : 'hidden' }}" data-module-grid>
                @foreach($moduleGroups as $section => $modules)
                    <div class="um-module-section">
                        <p class="um-module-section-title">{{ $section }}</p>
                        <div class="um-module-checkboxes">
                            @foreach($modules as $module)
                                @if(($module['always_available'] ?? false) || ($module['key'] === 'user_management' && ! $canGrantUserManagement))
                                    @continue
                                @endif
                                <label class="um-module-check">
                                    <input
                                        type="checkbox"
                                        name="modules[]"
                                        value="{{ $module['key'] }}"
                                        @checked(in_array($module['key'], $selectedModules, true))
                                    >
                                    <span>
                                        <span class="um-module-check-label">{{ $module['label'] }}</span>
                                        @if($module['description'])
                                            <span class="um-module-check-desc">{{ $module['description'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <button type="submit" class="um-btn um-btn-ghost um-btn-sm member-action-btn member-action-btn-role">Save module access</button>
        </form>
    </details>
@endif
