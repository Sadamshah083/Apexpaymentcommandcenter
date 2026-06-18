@props([
    'title',
    'subtitle' => null,
])

<div {{ $attributes->merge(['class' => 'app-page-header flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4']) }}>
    <div>
        <h1 class="app-page-title">{{ $title }}</h1>
        @if($subtitle)
            <p class="app-page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @if(isset($actions))
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endif
</div>
