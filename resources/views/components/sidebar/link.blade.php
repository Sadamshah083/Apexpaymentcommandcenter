@props(['href', 'active' => false, 'label'])

<a href="{{ $href }}" data-turbo-preload @class(['sidebar-link', 'sidebar-link-active' => $active])
    @if ($active) aria-current="page" @endif>
    @isset($icon)
        <span class="sidebar-link-icon">{!! $icon !!}</span>
    @endisset
    <span class="sidebar-link-label">{{ $label }}</span>
</a>
