<nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="pag-nav pag-nav-compact" data-pagination>
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
