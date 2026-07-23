@props([
    'status',
    'processingMode' => null,
])

@php
    $pill = \App\Support\WorkflowStatusLabel::for($status, $processingMode);
@endphp

<span {{ $attributes->merge(['class' => 'app-status-pill '.$pill['class']]) }}>
    {{ $pill['label'] }}
</span>
