@props(['status'])

@php
    $labels = [
        'mapping' => 'Setup',
        'pending' => 'Queued',
        'extracting' => 'Enriching',
        'paused' => 'Paused',
        'completed' => 'Complete',
        'failed' => 'Failed',
    ];
    $classes = [
        'mapping' => 'app-status-pill-setup',
        'pending' => 'app-status-pill-queued',
        'extracting' => 'app-status-pill-enriching',
        'paused' => 'app-status-pill-paused',
        'completed' => 'app-status-pill-complete',
        'failed' => 'app-status-pill-failed',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'app-status-pill ' . ($classes[$status] ?? 'app-status-pill-queued')]) }}>
    {{ $labels[$status] ?? $status }}
</span>
