@props(['href', 'active' => false, 'label'])

<a href="{{ $href }}" @class(['sidebar-link', 'sidebar-link-active' => $active]) data-turbo-preload
    @if ($active) aria-current="page" @endif>
    @isset($icon)
        <span class="sidebar-link-icon">{!! $icon !!}</span>
    @endisset
    <span class="sidebar-link-label">{{ $label }}</span>
</a>
