@props(['pagination' => null])

@php
    $pagination = $pagination ?? null;
@endphp

@if ($pagination && (($pagination['total'] ?? 0) > 0 || ($pagination['has_next'] ?? false)))
    <div {{ $attributes->class(['ghl-list-pagination']) }}>
        <span class="ghl-list-pagination-summary">
            @if (($pagination['total'] ?? 0) > 0)
                {{ $pagination['from'] }}–{{ $pagination['to'] }} of {{ $pagination['total'] }}
            @else
                Page {{ $pagination['page'] ?? 1 }}
            @endif
        </span>
        <div class="ghl-list-pagination-actions">
            @if (($pagination['has_prev'] ?? false) && filled($pagination['prev_url'] ?? null) && str_starts_with((string) $pagination['prev_url'], 'http'))
                <a href="{{ $pagination['prev_url'] }}" class="ghl-list-pagination-btn" data-turbo="false">&larr; Prev</a>
            @else
                <span class="ghl-list-pagination-btn ghl-list-pagination-btn-disabled">&larr; Prev</span>
            @endif
            @if (($pagination['last_page'] ?? 1) > 1)
                <span class="ghl-list-pagination-page">{{ $pagination['page'] }} / {{ $pagination['last_page'] }}</span>
            @endif
            @if (($pagination['has_next'] ?? false) && filled($pagination['next_url'] ?? null) && str_starts_with((string) $pagination['next_url'], 'http'))
                <a href="{{ $pagination['next_url'] }}" class="ghl-list-pagination-btn" data-turbo="false">
                    Next &rarr;
                </a>
            @else
                <span class="ghl-list-pagination-btn ghl-list-pagination-btn-disabled">Next &rarr;</span>
            @endif
        </div>
    </div>
@endif
