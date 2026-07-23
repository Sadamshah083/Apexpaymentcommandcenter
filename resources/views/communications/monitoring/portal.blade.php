@extends('layouts.portal')

@section('title', 'Call Monitoring')

@section('content')
    @include('communications.monitoring.partials.wallboard', [
        'routePrefix' => $routePrefix ?? 'portal.',
        'snapshot' => $snapshot ?? ['summary' => [], 'rows' => [], 'warnings' => [], 'generated_at' => now()->toIso8601String()],
        'pollUrl' => $pollUrl ?? '',
        'streamUrl' => $streamUrl ?? '',
        'wsUrl' => $wsUrl ?? '',
        'workspaceId' => $workspaceId ?? 0,
    ])
@endsection
