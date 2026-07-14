@php
    $viteEntries = ['resources/css/app.css', 'resources/js/app.js'];

    // Heavy communications CSS only on the dialer/inbox UI — not Call Monitoring.
    if (request()->routeIs('admin.communications.*', 'portal.communications.*')
        && ! request()->routeIs('admin.communications.monitoring*', 'portal.communications.monitoring*')) {
        $viteEntries[] = 'resources/css/communications-hub.css';
    }
@endphp
@vite($viteEntries)
