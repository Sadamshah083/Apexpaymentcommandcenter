@props([
    'variant' => 'neutral', // live | offline | warn | neutral
])

@php
    $class = match ($variant) {
        'live' => 'ch-badge ch-badge--live',
        'offline' => 'ch-badge ch-badge--offline',
        'warn' => 'ch-badge ch-badge--warn',
        default => 'ch-badge ch-badge--offline',
    };
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>{{ $slot }}</span>
