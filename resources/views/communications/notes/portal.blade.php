@extends('layouts.portal')

@section('title', 'Call Notes')

@section('content')
    @include('communications.notes.partials.panel', [
        'routePrefix' => $routePrefix ?? 'portal.',
        'isAdminView' => $isAdminView ?? false,
        'agents' => $agents ?? collect(),
        'selectedAgentId' => $selectedAgentId ?? 0,
        'selectedAgent' => $selectedAgent ?? null,
        'notes' => $notes ?? null,
        'downloadUrl' => $downloadUrl ?? null,
    ])
@endsection
