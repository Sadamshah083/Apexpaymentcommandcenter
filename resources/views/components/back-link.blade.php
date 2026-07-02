@props(['href', 'label' => 'Back'])

<a href="{{ $href }}" {{ $attributes->merge(['class' => 'app-back-link']) }}>
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    <span>{{ $label }}</span>
</a>
