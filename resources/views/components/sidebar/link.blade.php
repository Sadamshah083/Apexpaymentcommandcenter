@props(['href', 'active' => false, 'label', 'iconName' => null])

<a href="{{ $href }}" @class(['sidebar-link', 'sidebar-link-active' => $active]) data-turbo-preload
    @if ($active) aria-current="page" @endif>
    @isset($icon)
        <span @class([
            'sidebar-link-icon',
            'sidebar-link-icon--' . $iconName => filled($iconName),
        ])>{!! $icon !!}</span>
    @endisset
    <span class="sidebar-link-label">{{ $label }}</span>
</a>
