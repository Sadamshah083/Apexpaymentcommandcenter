@props([
    'paginator',
])

@if ($paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'app-pagination']) }} data-pagination>
        <p class="app-pagination-summary">
            Showing
            <span class="app-pagination-range">{{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }}</span>
            of
            <span class="app-pagination-total">{{ number_format($paginator->total()) }}</span>
        </p>

        <div class="app-pagination-controls">
            @if (method_exists($paginator, 'previousPageUrl') && method_exists($paginator, 'lastPage'))
                <nav class="pag-nav pag-nav-compact" role="navigation" aria-label="Pagination">
                    <div class="pag-nav-compact-inner">
                        @if ($paginator->onFirstPage())
                            <span class="pag-btn pag-btn-compact pag-btn-disabled" aria-disabled="true">Prev</span>
                        @else
                            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pag-btn pag-btn-compact" data-pagination-link>Prev</a>
                        @endif

                        <span class="pag-btn pag-btn-page" aria-current="page">
                            {{ max(1, (int) $paginator->currentPage()) }} / {{ max(1, (int) $paginator->lastPage()) }}
                        </span>

                        @if ($paginator->hasMorePages())
                            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pag-btn pag-btn-compact" data-pagination-link>Next</a>
                        @else
                            <span class="pag-btn pag-btn-compact pag-btn-disabled" aria-disabled="true">Next</span>
                        @endif
                    </div>
                </nav>
            @else
                {{ $paginator->links() }}
            @endif
        </div>
    </div>
@endif
