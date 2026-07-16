<nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="pag-nav pag-nav-compact" data-pagination>
    <div class="pag-nav-compact-inner">
        @if ($paginator->onFirstPage())
            <span class="pag-btn pag-btn-compact pag-btn-disabled" aria-disabled="true">&larr; Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pag-btn pag-btn-compact" data-pagination-link>&larr; Prev</a>
        @endif

        @if ($paginator->lastPage() > 1)
            <span class="pag-btn pag-btn-page" aria-current="page">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @else
            <span class="pag-btn pag-btn-page">Page 1</span>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pag-btn pag-btn-compact" data-pagination-link>Next &rarr;</a>
        @else
            <span class="pag-btn pag-btn-compact pag-btn-disabled" aria-disabled="true">Next &rarr;</span>
        @endif
    </div>
</nav>
