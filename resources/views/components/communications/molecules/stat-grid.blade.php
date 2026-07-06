@props([
    'items' => [],
])

<div {{ $attributes->merge(['class' => 'ch-stat-grid']) }}>
    @foreach ($items as $item)
        <div class="ch-stat-grid__item">
            <span class="ch-stat-grid__value">{{ $item['value'] ?? '—' }}</span>
            <span class="ch-stat-grid__label">{{ $item['label'] ?? '' }}</span>
        </div>
    @endforeach
</div>
