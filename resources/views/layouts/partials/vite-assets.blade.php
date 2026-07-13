@php
    $viteEntries = ['resources/css/app.css', 'resources/js/app.js'];

    if (request()->routeIs('admin.communications.*', 'portal.communications.*')) {
        $viteEntries[] = 'resources/css/communications-hub.css';
    }
@endphp
@vite($viteEntries)
