@props(['title'])

<div {{ $attributes->merge(['class' => 'sidebar-section']) }}>
    <p class="sidebar-section-title">{{ $title }}</p>
    <div class="sidebar-section-links">
        {{ $slot }}
    </div>
</div>
