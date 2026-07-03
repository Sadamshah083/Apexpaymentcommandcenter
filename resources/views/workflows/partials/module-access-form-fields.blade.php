@props([
    'selectedMode' => 'full',
    'selectedModules' => [],
    'workspaceId' => null,
    'memberRole' => 'admin',
    'moduleGroups' => null,
    'isCreateForm' => false,
])

@php
    use App\Support\MemberModuleAccess;

    $currentUser = auth()->user();
    $workspaceId = $workspaceId ?? $currentUser?->current_workspace_id;
    $isRestricted = $selectedMode === 'restricted';
    $configurableRoles = MemberModuleAccess::configurableRoles();
    $moduleGroups = $moduleGroups ?? MemberModuleAccess::groupedForCreateForm($currentUser, (int) $workspaceId);
@endphp

<div class="um-module-access-form-fields" data-role-labels='@json($configurableRoles)'>
    <input type="hidden" name="access_mode" value="{{ $isRestricted ? 'restricted' : 'full' }}" data-module-access-mode>

    <div class="um-field um-module-access-role-field" data-module-access-role-field>
        <label class="app-label">Account role</label>
        <div class="um-module-access-role-display" data-module-access-role-display data-role="{{ $memberRole }}">
            {{ $configurableRoles[$memberRole] ?? $memberRole }}
        </div>
    </div>

    <div class="um-field">
        <label class="app-label">Module access</label>
        <p class="um-panel-desc um-field-hint">Tick modules this account can open. Untick to remove access.</p>

        <div class="um-module-tick-list" data-module-tick-list data-member-role="{{ $memberRole }}">
            @foreach ($moduleGroups as $section => $modules)
                @if (empty($modules))
                    @continue
                @endif
                <div class="um-module-section">
                    <p class="um-module-section-title">{{ $section }}</p>
                    <div class="um-module-tick-items">
                        @foreach ($modules as $module)
                            @php
                                $moduleScopes = $module['scopes'] ?? [];
                                $appliesToMember = empty($moduleScopes)
                                    || in_array($memberRole, $moduleScopes, true)
                                    || in_array($memberRole, $module['roles'] ?? [], true);
                                $isChecked = $isRestricted
                                    ? in_array($module['key'], $selectedModules, true)
                                    : $appliesToMember;
                            @endphp
                            <label class="um-module-tick-item {{ $isChecked ? 'is-checked' : '' }}"
                                data-module-roles="{{ implode(',', $module['roles'] ?? []) }}"
                                data-module-scopes="{{ implode(',', $module['scopes'] ?? ($isCreateForm ? [] : [$memberRole])) }}">
                                <input type="checkbox" name="modules[]" value="{{ $module['key'] }}"
                                    class="um-module-tick-input" data-module-option @checked($isChecked)>
                                <span class="um-module-tick-item-body">
                                    <span class="um-module-check-label">{{ $module['label'] }}</span>
                                    @if ($module['description'])
                                        <span class="um-module-check-desc">{{ $module['description'] }}</span>
                                    @endif
                                </span>
                                <span class="um-module-tick-mark" aria-hidden="true">
                                    @include('workflows.partials.um-tick-icon')
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
