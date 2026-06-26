@props(['status'])

@php
    $labels = [
        'pending_verification' => 'Needs review',
        'completed' => 'Released',
        'extracting' => 'Enriching',
        'failed' => 'Failed',
        'pending' => 'Queued',
    ];
    $classes = [
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
