@props([
    'assignableRoles' => [],
    'selectedRole' => '',
])

@php
    $selectedLabel = $assignableRoles[$selectedRole] ?? $selectedRole;
@endphp

<div class="um-role-dropdown um-module-access-role-dropdown" data-module-access-role-dropdown>
    <select class="um-role-dropdown-native" data-module-access-role-select tabindex="-1" aria-label="Account role">
        @foreach ($assignableRoles as $value => $label)
            <option value="{{ $value }}" @selected($selectedRole === $value)>{{ $label }}</option>
        @endforeach
    </select>

    <button type="button" class="um-role-dropdown-trigger" aria-haspopup="listbox" aria-expanded="false">
        <span class="um-role-dropdown-label">{{ $selectedLabel }}</span>
        <svg class="um-role-dropdown-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
    </button>

    <div class="um-role-dropdown-menu" role="listbox" hidden>
        @foreach ($assignableRoles as $value => $label)
            <button type="button"
                class="um-role-dropdown-option {{ $selectedRole === $value ? 'is-selected' : '' }}"
                role="option" data-module-access-role-option data-value="{{ $value }}"
                aria-selected="{{ $selectedRole === $value ? 'true' : 'false' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
