@props([
    'paginator' => null,
    'title' => null,
    'minWidth' => null,
])

<div {{ $attributes->class(['app-data-table']) }}>
    @if (isset($header))
        <div class="app-data-table-header">{{ $header }}</div>
    @elseif($title)
        <div class="app-data-table-header">
            <h3 class="app-data-table-title">{{ $title }}</h3>
        </div>
    @endif

    <div class="app-table-wrap" @if ($minWidth) data-min-width="{{ $minWidth }}" @endif>
        {{ $slot }}
    </div>

    @if ($paginator && $paginator->total() > 0)
        <x-pagination :paginator="$paginator" class="app-data-table-footer" />
    @endif
</div>
