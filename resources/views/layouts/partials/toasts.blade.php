@php
    $flashMessages = [];

    if (session('success')) {
        $flashMessages[] = ['type' => 'success', 'message' => session('success')];
    }
    if (session('warning')) {
        $flashMessages[] = ['type' => 'warning', 'message' => session('warning')];
    }
    if (session('error')) {
        $flashMessages[] = ['type' => 'error', 'message' => session('error')];
    }
    if (session('info')) {
        $flashMessages[] = ['type' => 'info', 'message' => session('info')];
    }
    foreach ($errors->all() as $error) {
        $flashMessages[] = ['type' => 'error', 'message' => $error];
    }
@endphp

<div id="toast-container" class="app-toast-container" aria-live="polite" aria-atomic="true"></div>

@if(count($flashMessages) > 0)
    <script type="application/json" id="app-flash-messages">@json($flashMessages)</script>
@endif
