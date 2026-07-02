@props(['brandTitle' => null, 'brandSubtitle' => null, 'logoutRoute' => null])

@php
    $title = $brandTitle ?? config('app.name');
    $mark = config('app.brand.mark', 'A1');
@endphp

<aside class="app-sidebar">
    <div class="app-sidebar-header">
        <div class="app-sidebar-brand">
            <span class="app-sidebar-brand-mark" aria-hidden="true">{{ $mark }}</span>
            <div>
                <h1 class="app-sidebar-brand-title">{{ $title }}</h1>
                @if ($brandSubtitle)
                    <p class="app-sidebar-brand-subtitle">{{ $brandSubtitle }}</p>
                @endif
            </div>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        {!! $nav !!}
    </nav>
</aside>
