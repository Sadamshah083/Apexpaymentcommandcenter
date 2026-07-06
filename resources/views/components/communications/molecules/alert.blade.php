@props([
    'variant' => 'warning', // warning | info
    'title' => null,
])

<div {{ $attributes->merge(['class' => 'ch-alert ch-alert--' . $variant]) }}>
    <div>
        @if ($title)
            <p class="ch-alert__title">{{ $title }}</p>
        @endif
        <div class="ch-alert__body">{{ $slot }}</div>
    </div>
</div>
