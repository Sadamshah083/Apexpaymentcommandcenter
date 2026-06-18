@props(['paginator'])

@if ($paginator->total() > 0)
    <div {{ $attributes->merge(['class' => 'flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3']) }}>
        <p class="text-xs text-slate-500">
            Showing
            <span class="font-semibold text-slate-700">{{ number_format($paginator->firstItem() ?? 0) }}–{{ number_format($paginator->lastItem() ?? 0) }}</span>
            of
            <span class="font-semibold text-slate-700">{{ number_format($paginator->total()) }}</span>
        </p>

        @if ($paginator->hasPages())
            {{ $paginator->links() }}
        @endif
    </div>
@endif
