@props(['title' => null])

<div {{ $attributes->merge(['class' => 'sidebar-section']) }}>
    <div class="sidebar-section-links">
        {{ $slot }}
    </div>
</div>
