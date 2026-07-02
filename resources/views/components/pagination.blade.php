@if ($paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'app-pagination']) }}>
        <p class="app-pagination-summary">
            Showing
            <span class="font-semibold text-slate-700">{{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }}</span>
            of
            <span class="font-semibold text-slate-700">{{ number_format($paginator->total()) }}</span>
        </p>

        {{ $paginator->links() }}
    </div>
@endif
