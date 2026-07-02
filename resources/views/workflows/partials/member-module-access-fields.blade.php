@php
    use App\Support\AdminModules;

    $moduleGroups = AdminModules::groupedForUi();
    $canGrantUserManagement = auth()
        ->user()
        ?->isSuperAdmin($activeWorkspace->id ?? auth()->user()?->current_workspace_id);
@endphp

<div class="um-module-panel-inner">
    <div>
        <p class="um-label">Admin feature access</p>
        <p class="um-panel-desc">Limit which admin modules this account can open.</p>
    </div>

    <div class="um-access-mode">
        <label class="um-radio-pill">
            <input type="radio" name="access_mode" value="full" checked class="member-access-mode">
            <span>Full access</span>
        </label>
        <label class="um-radio-pill">
            <input type="radio" name="access_mode" value="restricted" class="member-access-mode">
            <span>Selected modules</span>
        </label>
    </div>

    <div class="member-module-grid um-module-grid hidden" data-module-grid>
        @foreach ($moduleGroups as $section => $modules)
            <div class="um-module-section">
                <p class="um-module-section-title">{{ $section }}</p>
                <div class="um-module-checkboxes">
                    @foreach ($modules as $module)
                        @if (($module['always_available'] ?? false) || ($module['key'] === 'user_management' && !$canGrantUserManagement))
                            @continue
                        @endif
                        <label class="um-module-check">
                            <input type="checkbox" name="modules[]" value="{{ $module['key'] }}">
                            <span>
                                <span class="um-module-check-label">{{ $module['label'] }}</span>
                                @if ($module['description'])
                                    <span class="um-module-check-desc">{{ $module['description'] }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
