@props([
    'title' => '',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'ch-section-header']) }}>
    <div>
        @if ($title)
            <h2 class="ch-section-header__title">{{ $title }}</h2>
        @endif
        @if ($subtitle)
            <p class="ch-section-header__subtitle">{{ $subtitle }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
