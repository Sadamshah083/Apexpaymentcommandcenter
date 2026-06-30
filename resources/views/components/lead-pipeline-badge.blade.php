@props(['status'])

@php
    $labels = [
        'imported' => 'Imported',
        'enriched' => 'Enriched',
        'pending_verification' => 'Needs review',
        'completed' => 'Released',
        'extracting' => 'Enriching',
        'failed' => 'Failed',
        'pending' => 'Queued',
    ];
    $classes = [
        'imported' => 'app-badge app-badge-muted',
        'enriched' => 'app-badge app-badge-info',
        'pending_verification' => 'app-badge app-badge-warning',
        'completed' => 'app-badge app-badge-success',
        'extracting' => 'app-badge app-badge-info',
        'failed' => 'app-badge app-badge-danger',
        'pending' => 'app-badge app-badge-muted',
    ];
@endphp

<span {{ $attributes->merge(['class' => $classes[$status] ?? 'app-badge app-badge-muted']) }}>
    {{ $labels[$status] ?? str_replace('_', ' ', $status) }}
</span>
