@extends('layouts.admin')

@section('title', 'Call Notes')

@section('content')
    @include('communications.notes.partials.panel', [
        'routePrefix' => $routePrefix ?? 'admin.',
        'isAdminView' => $isAdminView ?? true,
        'agents' => $agents ?? collect(),
        'selectedAgentId' => $selectedAgentId ?? 0,
        'selectedAgent' => $selectedAgent ?? null,
        'notes' => $notes ?? null,
        'downloadUrl' => $downloadUrl ?? null,
    ])
@endsection
