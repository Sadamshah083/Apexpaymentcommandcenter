@props([
    'variant' => 'primary', // primary | secondary | call
    'size' => 'md', // md | sm
    'type' => 'button',
])

@php
    $classes = ['ch-btn'];
    $classes[] = match ($variant) {
        'secondary' => 'ch-btn--secondary',
        'call' => 'ch-btn--call',
        default => 'ch-btn--primary',
    };
    if ($size === 'sm') {
        $classes[] = 'ch-btn--sm';
    }
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' => implode(' ', $classes)]) }}>
    {{ $slot }}
</button>
