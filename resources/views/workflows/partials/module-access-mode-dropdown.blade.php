@props([
    'selectedMode' => 'full',
])

@php
    $options = [
        'full' => 'Full access',
        'restricted' => 'Selected modules',
    ];
    $selectedLabel = $options[$selectedMode] ?? $options['full'];
@endphp

<div class="um-role-dropdown um-access-mode-dropdown" data-access-mode-dropdown>
    <select name="access_mode" class="um-role-dropdown-native member-access-mode" tabindex="-1" aria-label="Access level">
        @foreach ($options as $value => $label)
            <option value="{{ $value }}" @selected($selectedMode === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <button type="button" class="um-role-dropdown-trigger" aria-haspopup="listbox" aria-expanded="false">
        <span class="um-role-dropdown-label">{{ $selectedLabel }}</span>
        <svg class="um-role-dropdown-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div class="um-role-dropdown-menu" role="listbox" hidden>
        @foreach ($options as $value => $label)
            <button type="button"
                class="um-role-dropdown-option {{ $selectedMode === $value ? 'is-selected' : '' }}"
                role="option" data-access-mode-option data-value="{{ $value }}"
                aria-selected="{{ $selectedMode === $value ? 'true' : 'false' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
