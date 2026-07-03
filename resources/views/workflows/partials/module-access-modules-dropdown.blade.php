@props([
    'moduleGroups' => [],
    'selectedModules' => [],
    'workspaceId' => null,
])

@php
    use App\Support\AdminModules;

    $currentUser = auth()->user();
    $workspaceId = $workspaceId ?? $currentUser?->current_workspace_id;
    $selectedModules = $selectedModules ?? [];
    $selectedCount = count($selectedModules);

    if ($selectedCount === 0) {
        $pickerLabel = 'Select modules…';
    } elseif ($selectedCount === 1) {
        $pickerLabel = AdminModules::labels()[$selectedModules[0]] ?? $selectedModules[0];
    } else {
        $pickerLabel = "{$selectedCount} modules selected";
    }
@endphp

<div class="um-module-picker" data-module-picker>
    <button type="button" class="um-role-dropdown-trigger um-module-picker-trigger" aria-haspopup="listbox"
        aria-expanded="false">
        <span class="um-module-picker-label">{{ $pickerLabel }}</span>
        <svg class="um-role-dropdown-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div class="um-module-picker-menu" role="listbox" hidden>
        @foreach ($moduleGroups as $section => $modules)
            @php
                $sectionModules = collect($modules)->filter(function (array $module) use ($currentUser, $workspaceId) {
                    if ($module['always_available'] ?? false) {
                        return false;
                    }

                    return AdminModules::canGrantModule($module['key'], $currentUser, (int) $workspaceId);
                });
            @endphp

            @if ($sectionModules->isEmpty())
                @continue
            @endif

            <div class="um-module-picker-section">
                <p class="um-module-section-title">{{ $section }}</p>
                @foreach ($sectionModules as $module)
                    <label class="um-module-picker-option">
                        <input type="checkbox" name="modules[]" value="{{ $module['key'] }}"
                            data-module-option @checked(in_array($module['key'], $selectedModules, true))>
                        <span class="um-module-picker-option-text">
                            <span class="um-module-check-label">{{ $module['label'] }}</span>
                            @if ($module['description'])
                                <span class="um-module-check-desc">{{ $module['description'] }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
