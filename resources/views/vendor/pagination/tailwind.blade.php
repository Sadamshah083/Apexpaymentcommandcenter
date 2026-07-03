<nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="pag-nav pag-nav-compact">
    <div class="pag-nav-compact-inner">
        @if ($paginator->onFirstPage())
            <span class="pag-btn pag-btn-compact pag-btn-disabled">&larr; Prev</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pag-btn pag-btn-compact">&larr; Prev</a>
        @endif

        @if ($paginator->lastPage() > 1)
            <span class="pag-btn pag-btn-page">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @endif

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pag-btn pag-btn-compact">Next &rarr;</a>
        @else
            <span class="pag-btn pag-btn-compact pag-btn-disabled">Next &rarr;</span>
        @endif
    </div>
</nav>
