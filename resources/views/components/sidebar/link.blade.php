@props([
    'href',
    'active' => false,
    'label',
    'iconName' => null,
    'queryMode' => null,
    'matchPrefixes' => [],
])

@php
    $navPath = parse_url($href, PHP_URL_PATH) ?: $href;
    $navQuery = parse_url($href, PHP_URL_QUERY) ?: '';
    $resolvedQueryMode = $queryMode ?? ($navQuery !== '' ? 'exact' : 'ignore');
    $navMatchPrefixes = collect($matchPrefixes)
        ->filter(fn ($prefix) => is_string($prefix) && $prefix !== '')
        ->values()
        ->all();
@endphp

<a href="{{ $href }}" @class(['sidebar-link', 'sidebar-link-active' => $active]) data-turbo-preload
    data-nav-path="{{ $navPath }}"
    data-nav-query="{{ $navQuery }}"
    data-nav-query-mode="{{ $resolvedQueryMode }}"
    @if ($navMatchPrefixes !== []) data-nav-match-prefixes='@json($navMatchPrefixes)' @endif
    @if ($active) aria-current="page" @endif>
    @isset($icon)
        <span @class([
            'sidebar-link-icon',
            'sidebar-link-icon--' . $iconName => filled($iconName),
        ])>{!! $icon !!}</span>
    @endisset
    <span class="sidebar-link-label">{{ $label }}</span>
</a>
