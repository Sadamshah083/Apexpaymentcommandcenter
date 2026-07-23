@props([
    'label' => 'Import file',
    'sm' => false,
    'class' => '',
])

@php
    $href = route('admin.workflows.create');
    $classes = trim(implode(' ', array_filter([
        'app-btn app-btn-success',
        $sm ? 'app-btn-sm' : '',
        'shrink-0',
        $class,
    ])));
@endphp

<a href="{{ $href }}"
    class="{{ $classes }}"
    data-turbo-preload
    data-import-file-nav
    {{ $attributes }}>
    @if (! $slot->isEmpty())
        {{ $slot }}
    @else
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        {{ $label }}
    @endif
</a>
